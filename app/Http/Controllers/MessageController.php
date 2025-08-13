<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Models\Thread;
use App\Events\Webhook;
use App\Models\Message;
use App\Models\Numeros;
use App\Models\Contacto;
use PhpParser\Node\Expr;
use App\Jobs\SendMessage;
use App\Libraries\Whatsapp;
use App\Models\Aplicaciones;
use Illuminate\Http\Request;
use App\Http\Controllers\BotIA;
use Illuminate\Support\Facades\DB;
use OpenAI\Laravel\Facades\OpenAI;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;


class MessageController extends Controller
{


    public function verifyWebhook(Request $request)
    {
        try {
            $verifyToken = env('WHATSAPP_VERIFY_TOKEN');
            $query = $request->query();

            $mode = $query['hub_mode'];
            $token = $query['hub_verify_token'];
            $challenge = $query['hub_challenge'];

            if ($mode && $token) {
                if ($mode === 'subscribe' && $token == $verifyToken) {
                    return response($challenge, 200)->header('Content-Type', 'text/plain');
                }
            }

            throw new Exception('Invalid request');
        } catch (Exception $e) {
            Log::error('Error al obtener mensajes5: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function processWebhook(Request $request)
    {
        try {
            // A) Parseo seguro
            $body = json_decode($request->getContent(), true) ?? [];
            $value = data_get($body, 'entry.0.changes.0.value', []);

            // B) Actualización de estatus (sent|delivered|read|failed)
            if (!empty(data_get($value, 'statuses'))) {
                $statusItem = data_get($value, 'statuses.0', []);
                $status = data_get($statusItem, 'status');
                $statusWamId = data_get($statusItem, 'id');

                if ($statusWamId) {
                    $wam = Message::where('wam_id', $statusWamId)->first();
                    if ($wam) {
                        $wam->status = $status ?? $wam->status;

                        if ($status === 'failed') {
                            $errMsg = data_get($statusItem, 'errors.0.message', 'Unknown error');
                            $errCode = data_get($statusItem, 'errors.0.code', 'Unknown code');
                            $errDet = data_get($statusItem, 'errors.0.error_data.details', 'No additional details');
                            Log::error("Webhook processing error: {$errMsg}, Code: {$errCode}, Details: {$errDet}");
                            $wam->caption = (string) $errCode;
                        }

                        $wam->save();
                        Webhook::dispatch($wam, true);
                    }
                }

                // C) Mensajes entrantes
            } elseif (!empty(data_get($value, 'messages'))) {
                $msg = data_get($value, 'messages.0', []);
                $contacts0 = data_get($value, 'contacts.0', []);
                $incomingId = data_get($msg, 'id');
                $fromWaId = data_get($msg, 'from'); // remitente
                $incomingTyp = data_get($msg, 'type'); // text|audio|image|...
                $phoneId = data_get($value, 'metadata.phone_number_id');
                $timestamp = data_get($msg, 'timestamp');

                // Idempotencia
                if (Message::where('wam_id', $incomingId)->exists()) {
                    return response()->json(['success' => true, 'data' => 'duplicate'], 200);
                }

                // Contacto
                $waId = data_get($contacts0, 'wa_id', $fromWaId);
                $nombre = data_get($contacts0, 'profile.name', $waId);
                $contacto = Contacto::firstOrCreate(
                    ['telefono' => $waId],
                    ['nombre' => $nombre ?: $waId, 'notas' => 'Contacto creado automáticamente por webhook']
                );
                if ($contacto->nombre === $contacto->telefono && $nombre) {
                    $contacto->nombre = $nombre;
                    $contacto->notas = 'Nombre actualizado por webhook';
                    $contacto->save();
                }
                // Tag opcional (no revienta si no hay sesión)
                try {
                    $contacto->tags()->syncWithoutDetaching([22 => ['user_id' => auth()->id() ?? null]]);
                } catch (\Throwable $e) {
                    Log::warning('No se pudieron asociar tags: ' . $e->getMessage());
                }

                // Contexto de app/bot/token (helper)
                [$num, $app, $bot, $token] = $this->resolveAppContext($phoneId);

                // Tipos soportados de media
                $mediaSupported = ['audio', 'document', 'image', 'video', 'sticker'];

                // 1) TEXTO
                if ($incomingTyp === 'text') {
                    $text = (string) data_get($msg, 'text.body', '');

                    // Guardar entrante
                    // Guardar entrante (texto) SIN marcar bandeja humana todavía
                    $phoneForMsg = $num->id_telefono ?? $phoneId;
                    $this->_saveMessage(
                        $text,
                        'text',
                        $fromWaId,
                        $incomingId,
                        $phoneForMsg,
                        $timestamp,
                        null,   // caption
                        '',     // data
                        false,  // outgoing
                        true,   // fireWebhook
                        false   // markForHuman (lo decides después según haya bot)
                    );

                    if ($this->shouldAutoReply($app, $bot)) {
                        try {
                            $botIA = new BotIA();
                            $answer = $botIA->ask($text, $waId, $bot->id, $bot->openai_key, $bot->openai_org, $bot->openai_assistant, "");
                            $wh = new Whatsapp();
                            $sent = $wh->sendText($waId, $answer, $num->id_telefono, $token);

                            // outgoing=true, fireWebhook=true, markForHuman=false
                            $this->_saveMessage(
                                $answer,
                                'ia',
                                $waId,
                                data_get($sent, 'messages.0.id'),
                                $num->id_telefono,
                                null,          // timestamp (opcional)
                                '',            // caption
                                '',            // data
                                true,          // outgoing
                                true,          // fireWebhook
                                false          // markForHuman
                            );

                        } catch (\Throwable $e) {
                            Log::error('IA (texto) falló: ' . $e->getMessage());
                            $this->markForHuman($contacto);
                        }
                    } else {
                        Log::info('No auto-reply (texto): app o bot inexistente.');
                        $this->markForHuman($contacto);
                    }
                }
                // 2) MEDIA
                elseif (in_array($incomingTyp, $mediaSupported, true)) {
                    $mediaId = data_get($msg, "{$incomingTyp}.id");
                    $caption = data_get($msg, "{$incomingTyp}.caption");

                    if (!$app || !$token) {
                        Log::warning('Media recibida sin app/token. Se marca humano.');
                        $this->_saveMessage(
                            "($incomingTyp) sin app/token",
                            'other',
                            $fromWaId,
                            $incomingId,
                            $phoneId,
                            $timestamp,
                            null,
                            '',
                            false,
                            true,
                            false // ← para no marcar dos veces
                        );
                        $this->markForHuman($contacto);
                    } else {
                        $wp = new Whatsapp();
                        $file = null;
                        try {
                            $file = $wp->downloadMedia($mediaId, $token);
                        } catch (\Throwable $e) {
                            Log::error('Error descargando media: ' . $e->getMessage());
                        }

                        if ($file) {
                            // Guardar entrante como link
                            $phoneForMsg = $num->id_telefono ?? $phoneId;
                            $this->_saveMessage(
                                env('APP_URL_MG') . '/storage/' . $file,
                                $incomingTyp,
                                $waId,
                                $incomingId,
                                $phoneForMsg,
                                $timestamp,
                                $caption ?? '',
                                '',
                                false,  // outgoing
                                true,   // fireWebhook
                                false   // markForHuman -> lo harás luego según corresponda
                            );


                            // AUDIO con IA
                            if ($incomingTyp === 'audio') {
                                if (!$this->shouldAutoReply($app, $bot)) {
                                    Log::info('Audio sin bot. No auto-reply.');
                                    $this->markForHuman($contacto);
                                } else {
                                    try {
                                        config(['openai.api_key' => $bot->openai_key, 'openai.organization' => $bot->openai_org]);

                                        $resp = OpenAI::audio()->transcribe([
                                            'model' => 'whisper-1',
                                            'file' => fopen(storage_path('app/public/' . $file), 'r'),
                                            'response_format' => 'verbose_json',
                                            'timestamp_granularities' => ['segment', 'word']
                                        ]);
                                        $text = (string) data_get($resp, 'text', '');

                                        $botIA = new BotIA();
                                        $answer = $botIA->ask($text, $fromWaId, $bot->id, $bot->openai_key, $bot->openai_org, $bot->openai_assistant, "");
                                        $wh = new Whatsapp();
                                        $sent = $wh->sendText($fromWaId, $answer, $num->id_telefono, $token);

                                        // outgoing=true, fireWebhook=true, markForHuman=false
                                        $this->_saveMessage(
                                            $answer,
                                            'ia',
                                            $waId,
                                            data_get($sent, 'messages.0.id'),
                                            $num->id_telefono,
                                            null,
                                            '',
                                            '',
                                            true,
                                            true,
                                            false
                                        );
                                    } catch (\Throwable $e) {
                                        Log::error('IA (audio) falló: ' . $e->getMessage());
                                        $this->markForHuman($contacto);
                                    }
                                }
                            }

                            // IMAGEN con IA
                            if ($incomingTyp === 'image') {
                                if (!$this->shouldAutoReply($app, $bot)) {
                                    Log::info('Imagen sin bot. No auto-reply.');
                                    $this->markForHuman($contacto);
                                } else {
                                    try {
                                        config(['openai.api_key' => $bot->openai_key, 'openai.organization' => $bot->openai_org]);

                                        $imgUrl = env('APP_URL_MG') . '/storage/' . $file;
                                        if (!file_exists(storage_path('app/public/' . $file))) {
                                            Log::error('La imagen no existe en storage: ' . storage_path('app/public/' . $file));
                                        } else {
                                            $finalCaption = $caption ?: 'Revisa la imagen y responde acorde a la charla';
                                            $botIA = new BotIA();
                                            $answer = $botIA->ask($finalCaption, $fromWaId, $bot->id, $bot->openai_key, $bot->openai_org, $bot->openai_assistant, $imgUrl);

                                            $wh = new Whatsapp();
                                            $sent = $wh->sendText($fromWaId, $answer, $num->id_telefono, $token);

                                            // outgoing=true, fireWebhook=true, markForHuman=false
                                            $this->_saveMessage(
                                                $answer,
                                                'ia',
                                                $waId,
                                                data_get($sent, 'messages.0.id'),
                                                $num->id_telefono,
                                                null,
                                                '',
                                                '',
                                                true,
                                                true,
                                                false
                                            );
                                        }
                                    } catch (\Throwable $e) {
                                        Log::error('IA (imagen) falló: ' . $e->getMessage());
                                        $this->markForHuman($contacto);
                                    }
                                }
                            }

                            // Otros media → por defecto humano
                            if (!in_array($incomingTyp, ['audio', 'image'], true)) {
                                $this->markForHuman($contacto);
                            }
                        } else {
                            // No descargó
                            $this->_saveMessage("($incomingTyp) no descargado", 'other', $fromWaId, $incomingId, $phoneId, $timestamp);
                            $this->markForHuman($contacto);
                        }
                    }
                }
                // 3) Tipo no soportado
                else {
                    $payloadSafe = substr(json_encode($msg), 0, 2000);
                    $this->_saveMessage(
                        "(unsupported {$incomingTyp}): \n _{$payloadSafe}_",
                        'other',
                        $fromWaId,
                        $incomingId,
                        $phoneId,
                        $timestamp,
                        null,
                        '',
                        false,
                        true,
                        false // si luego llamas markForHuman($contacto)
                    );
                    $this->markForHuman($contacto);
                }
            }

            // D) Siempre 200
            return response()->json(['success' => true, 'data' => 'ok'], 200);

        } catch (\Throwable $e) {
            Log::error('Error al procesar webhook: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            Log::error('Body: ' . $request->getContent());
            // Evita reintentos masivos de Meta
            return response()->json(['success' => true, 'data' => 'handled_with_errors'], 200);
        }
    }

    /* ===================== HELPERS ===================== */

    /**
     * Resuelve Numeros, Aplicaciones, Bot y token api desde phoneNumberId.
     * @return array [$num, $app, $bot, $token]
     */
    private function resolveAppContext($phoneNumberId)
    {
        if (!$phoneNumberId)
            return [null, null, null, null];

        $num = Numeros::where('id_telefono', $phoneNumberId)->first();
        if (!$num)
            return [null, null, null, null];

        $app = Aplicaciones::find($num->aplicacion_id);
        if (!$app)
            return [$num, null, null, null];

        $bot = $app->bot->first();
        $tk = $app->token_api ?? null;

        return [$num, $app, $bot, $tk];
    }

    /**
     * Define si se debe contestar automáticamente.
     */
    private function shouldAutoReply($app, $bot): bool
    {
        return !is_null($app) && !is_null($bot);
    }

    /**
     * Marca contacto para gestión humana (inbox).
     */
    private function markForHuman(?Contacto $contacto): void
    {
        if (!$contacto)
            return;
        try {
            $contacto->tiene_mensajes_nuevos = true;
            $contacto->save();
        } catch (\Throwable $e) {
            Log::warning('No se pudo marcar para humano: ' . $e->getMessage());
        }
    }




    private function _saveMessage(
        $message,
        $messageType,
        $waId,
        $wamId,
        $phoneId,
        $timestamp = null,
        $caption = null,
        $data = '',
        bool $outgoing = false,          // NUEVO: por defecto es entrante
        bool $fireWebhook = true,        // NUEVO: disparar evento
        bool $markForHuman = true        // NUEVO: marcar bandeja humana solo si es entrante
    ) {
        $wam = new Message();
        $wam->body = (string) $message;
        $wam->outgoing = $outgoing;
        $wam->type = $messageType;
        $wam->wa_id = $waId;
        $wam->wam_id = $wamId;
        $wam->phone_id = $phoneId;

        // Status coherente con la dirección del mensaje
        $wam->status = $outgoing ? 'sent' : 'received';

        $wam->caption = $caption ?? '';
        $wam->data = $data ?? '';

        // Normalizar timestamp (WhatsApp suele enviar en segundos; por si llega en ms)
        if (is_numeric($timestamp)) {
            $ts = (int) $timestamp;
            // Si parece milisegundos (>= 13 dígitos), convertir a segundos
            if (strlen((string) $ts) >= 13) {
                $ts = intdiv($ts, 1000);
            }
            try {
                $dt = \Carbon\Carbon::createFromTimestampUTC($ts); // UTC safe
                $wam->created_at = $dt->toDateTimeString();
                $wam->updated_at = $dt->toDateTimeString();
            } catch (\Throwable $e) {
                \Log::warning('Timestamp inválido en _saveMessage: ' . $e->getMessage());
            }
        }

        // Guardar con manejo básico de duplicados por wam_id (si tienes índice único)
        try {
            $wam->save();
        } catch (\Throwable $e) {
            \Log::error('Error guardando Message (posible wam_id duplicado): ' . $e->getMessage());
            // Intentar recuperar el existente para no romper el flujo
            $wam = Message::where('wam_id', $wamId)->first() ?? $wam;
        }

        // Disparar webhook (si corresponde)
        if ($fireWebhook) {
            try {
                Webhook::dispatch($wam, $outgoing /* isStatus? -> aquí false para mensajes, true lo usas en estatus */);
            } catch (\Throwable $e) {
                \Log::warning('No se pudo despachar Webhook en _saveMessage: ' . $e->getMessage());
            }
        }

        // Log breve (evita exponer datos sensibles)
        \Log::info(sprintf(
            'Mensaje %s guardado: type=%s wam_id=%s len=%d',
            $outgoing ? 'saliente' : 'entrante',
            $messageType,
            $wamId,
            mb_strlen((string) $message)
        ));

        // Marcar contacto para gestión humana SOLO si es entrante y así se solicita
        if (!$outgoing && $markForHuman) {
            try {
                $contacto = Contacto::where('telefono', $waId)->first();
                if ($contacto) {
                    $contacto->tiene_mensajes_nuevos = true;
                    $contacto->save();
                }
            } catch (\Throwable $e) {
                \Log::warning('No se pudo marcar contacto con mensajes nuevos: ' . $e->getMessage());
            }
        }

        return $wam;
    }



}
