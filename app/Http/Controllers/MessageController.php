<?php

namespace App\Http\Controllers;

use Exception;
use Carbon\Carbon;
use App\Events\Webhook;
use App\Models\Message;
use App\Models\Numeros;
use App\Models\Contacto;
use PhpParser\Node\Expr;
use App\Jobs\SendMessage;
use App\Libraries\Whatsapp;
use App\Models\Aplicaciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;


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
            $bodyContent = json_decode($request->getContent(), true);
            $body = '';

            // Determine what happened...
            $value = $bodyContent['entry'][0]['changes'][0]['value'];

            if (!empty($value['statuses'])) {
                $status = $value['statuses'][0]['status']; // sent, delivered, read, failed
                $wam = Message::where('wam_id', $value['statuses'][0]['id'])->first();

                if (!empty($wam->id)) {
                    $wam->status = $status;
                    $wam->save();
                    Webhook::dispatch($wam, true);
                }
                // Si el estado es 'failed', procesar y registrar los detalles del error
                if ($status == 'failed') {
                    $errorMessage = $value['statuses'][0]['errors'][0]['message'] ?? 'Unknown error';
                    $errorCode = $value['statuses'][0]['errors'][0]['code'] ?? 'Unknown code';
                    $errorDetails = $value['statuses'][0]['errors'][0]['error_data']['details'] ?? 'No additional details';

                    // Registrar el error en los logs de Laravel
                    Log::error("Webhook processing error: {$errorMessage}, Code: {$errorCode}, Details: {$errorDetails}");

                    // Aquí podrías agregar lógica adicional si necesitas manejar estos errores de manera específica
                    // Por ejemplo, notificar al equipo de soporte, realizar reintento condicional, etc.
                    if (!empty($wam->id)) {
                        $wam->caption = $errorCode;
                        $wam->save();
                        Webhook::dispatch($wam, true);
                    }
                }
            } else if (!empty($value['messages'])) { // Message
                $exists = Message::where('wam_id', $value['messages'][0]['id'])->first();

                if (empty($exists->id)) {

                    // Verificar si el contacto existe
                    $contacto = Contacto::where('telefono', $value['messages'][0]['from'])->first();
                    // Si no existe, crearlo
                    if (!$contacto) {
                        $contacto = Contacto::createWithDefaultTag([
                            'nombre' => $value['contacts'][0]['profile']['name'],  // Asumiendo que no sabemos el nombre
                            'telefono' => $value['contacts'][0]['profile']['wa_id'],
                            'notas' => 'Contacto creado automáticamente por webhook'
                        ]);
                    }else if ($contacto->nombre == $contacto->telefono){
                        $contacto->nombre = $value['contacts'][0]['profile']['name'];
                        $contacto->save();
                    }
                    $mediaSupported = ['audio', 'document', 'image', 'video', 'sticker'];

                    if ($value['messages'][0]['type'] == 'text') {
                        $message = $this->_saveMessage(
                            $value['messages'][0]['text']['body'],
                            'text',
                            $value['messages'][0]['from'],
                            $value['messages'][0]['id'],
                            $value['metadata']['phone_number_id'],
                            $value['messages'][0]['timestamp']
                        );

                        Webhook::dispatch($message, false);
                    } else if (in_array($value['messages'][0]['type'], $mediaSupported)) {
                        $mediaType = $value['messages'][0]['type'];
                        $mediaId = $value['messages'][0][$mediaType]['id'];
                        $wp = new Whatsapp();
                        //consulta para traer token
                        $num = Numeros::where('id_telefono', $value['metadata']['phone_number_id'])->first();

                        $app = Aplicaciones::where('id', $num->aplicacion_id)->first();

                        $tk = $app->token_api;

                        //fin de consulta
                        $file = $wp->downloadMedia($mediaId, $tk);

                        $caption = null;
                        if (!empty($value['messages'][0][$mediaType]['caption'])) {
                            $caption = $value['messages'][0][$mediaType]['caption'];
                        }

                        if (!is_null($file)) {
                            $message = $this->_saveMessage(
                                env('APP_URL_MG') . '/storage/' . $file,
                                $mediaType,
                                $value['messages'][0]['from'],
                                $value['messages'][0]['id'],
                                $value['metadata']['phone_number_id'],
                                $value['messages'][0]['timestamp'],
                                $caption
                            );
                            Webhook::dispatch($message, false);
                        }
                    } else {
                        $type = $value['messages'][0]['type'];
                        if (!empty($value['messages'][0][$type])) {
                            $message = $this->_saveMessage(
                                "($type): \n _" . serialize($value['messages'][0][$type]) . "_",
                                'other',
                                $value['messages'][0]['from'],
                                $value['messages'][0]['id'],
                                $value['metadata']['phone_number_id'],
                                $value['messages'][0]['timestamp']
                            );
                        }
                        Webhook::dispatch($message, false);
                    }
                }
            }
            return response()->json([
                'success' => true,
                'data' => $body,
            ], 200);
        } catch (Exception $e) {
            Log::error('Error al obtener mensajes6: ' . $e->getMessage());
            Log::error('Exception trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    private function _saveMessage($message, $messageType, $waId, $wamId, $phoneId, $timestamp = null, $caption = null, $data = '')
    {
        $wam = new Message();
        $wam->body = $message;
        $wam->outgoing = false;
        $wam->type = $messageType;
        $wam->wa_id = $waId;
        $wam->wam_id = $wamId;
        $wam->phone_id = $phoneId;
        $wam->status = 'sent';
        $wam->caption = $caption;
        $wam->data = $data;

        if (!is_null($timestamp)) {
            $wam->created_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
            $wam->updated_at = Carbon::createFromTimestamp($timestamp)->toDateTimeString();
        }
        $wam->save();

        return $wam;
    }


}
