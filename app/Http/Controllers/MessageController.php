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
            // Decode the incoming request content
            $bodyContent = json_decode($request->getContent(), true);
            $value = $bodyContent['entry'][0]['changes'][0]['value'];

            // Check if there are statuses to process
            if (!empty($value['statuses'])) {
                $status = $value['statuses'][0]['status']; // sent, delivered, read, failed
                $wam = Message::where('wam_id', $value['statuses'][0]['id'])->first();

                // Update message status if the message exists in the database
                if (!empty($wam->id)) {
                    $wam->status = $status;
                    $wam->save();
                    Webhook::dispatch($wam, true);
                }

                // Log error details if the status is 'failed'
                if ($status == 'failed') {
                    $errorMessage = $value['statuses'][0]['errors'][0]['message'] ?? 'Unknown error';
                    $errorCode = $value['statuses'][0]['errors'][0]['code'] ?? 'Unknown code';
                    $errorDetails = $value['statuses'][0]['errors'][0]['error_data']['details'] ?? 'No additional details';

                    Log::error("Webhook processing error: {$errorMessage}, Code: {$errorCode}, Details: {$errorDetails}");

                    // Save the error code in the caption field of the message, if the message exists
                    if (!empty($wam->id)) {
                        $wam->caption = $errorCode;
                        $wam->save();
                        Webhook::dispatch($wam, true);
                    }
                }

            } else if (!empty($value['messages'])) { // Check if there are messages to process

                // Check if the contact exists
                $contacto = Contacto::where('telefono', $value['contacts'][0]['wa_id'])->first();

                // Create new contact if it does not exist
                if (!$contacto) {
                    $contacto = new Contacto();
                    $contacto->telefono = $value['contacts'][0]['wa_id'];
                    $contacto->nombre = $value['contacts'][0]['profile']['name'];
                    $contacto->notas = "Contacto creado por webhook";
                    $contacto->save();

                    // Attach selected tags to the new contact
                    $contacto->tags()->attach(22);

                } else if ($contacto->nombre == $contacto->telefono) {
                    $contacto->nombre = $value['contacts'][0]['profile']['name'];
                    $contacto->notas = "Nombre actualizado por webhook";
                    $contacto->save();
                }
                // Check if the message already exists
                $exists = Message::where('wam_id', $value['messages'][0]['id'])->first();
                if (empty($exists->id)) {
                    $mediaSupported = ['audio', 'document', 'image', 'video', 'sticker'];

                    // Process text messages
                    if ($value['messages'][0]['type'] == 'text') {
                        $message = $this->_saveMessage(
                            $value['messages'][0]['text']['body'],
                            'text',
                            $value['messages'][0]['from'],
                            $value['messages'][0]['id'],
                            $value['metadata']['phone_number_id'],
                            $value['messages'][0]['timestamp']
                        );

                        // Recuperar o crear un hilo
                        $userWaId = $value['contacts'][0]['wa_id'];
                        $messageBody = $value['messages'][0]['text']['body'];




                        //enviar respuesta del bot al whatsapp
                        $respuesta = new Whatsapp();
                        $num = Numeros::where('id_telefono', $value['metadata']['phone_number_id'])->first();
                        $app = Aplicaciones::where('id', $num->aplicacion_id)->first();

                        // Verificar si existe la aplicación
                        if ($app) {
                            // Obtener el bot asociado a la aplicación
                            $bot = $app->bot->first(); // Esto obtiene el primer bot asociado a la aplicación

                            // Ahora tienes acceso a los datos del bot
                            if ($bot) {
                                $botIA = new BotIA();
                                $answer = $botIA->ask($messageBody, $userWaId, $bot->id, $bot->openai_key, $bot->openai_org, $bot->openai_assistant);
                                // Otros datos del bot que necesites...
                            } else {
                                // Maneja el caso donde no hay un bot asociado
                                echo "No hay un bot asociado a esta aplicación.";
                            }

                        } else {
                            echo "No se encontró la aplicación.";
                        }

                        $tk = $app->token_api;
                        $response = $respuesta->sendText($userWaId, $answer, $num->id_telefono, $app->token_api);

                        $message = new Message();
                        $message->wa_id = $value['contacts'][0]['wa_id'];
                        $message->wam_id = $response["messages"][0]["id"];
                        $message->phone_id = $num->id_telefono;
                        $message->type = 'text';
                        $message->outgoing = true;
                        $message->body = $answer;
                        $message->status = 'sent';
                        $message->caption = '';
                        $message->data = '';
                        $message->save();


                        Webhook::dispatch($message, false);
                    }
                    // Process media messages
                    else if (in_array($value['messages'][0]['type'], $mediaSupported)) {
                        $mediaType = $value['messages'][0]['type'];
                        $mediaId = $value['messages'][0][$mediaType]['id'];
                        $wp = new Whatsapp();
                        $num = Numeros::where('id_telefono', $value['metadata']['phone_number_id'])->first();
                        $app = Aplicaciones::where('id', $num->aplicacion_id)->first();
                        $tk = $app->token_api;
                        $file = $wp->downloadMedia($mediaId, $tk);

                        $caption = $value['messages'][0][$mediaType]['caption'] ?? null;

                        if (!is_null($file)) {
                            // Guardar el audio recibido con el link
                            $audioMessage = new Message();
                            $audioMessage->wa_id = $value['contacts'][0]['wa_id'];
                            $audioMessage->wam_id = $value['messages'][0]['id'];
                            $audioMessage->phone_id = $num->id_telefono;
                            $audioMessage->type = $mediaType; // Puede ser audio, documento, imagen, etc.
                            $audioMessage->outgoing = false;
                            $audioMessage->body = env('APP_URL_MG') . '/storage/' . $file; // Guardar solo el enlace del archivo
                            $audioMessage->status = 'received';
                            $audioMessage->caption = $caption ?? '';
                            $audioMessage->data = '';
                            $audioMessage->save();

                            Webhook::dispatch($audioMessage, false);

                            if ($mediaType == 'audio') {
                                $bot = $app->bot->first();
                                config(['openai.api_key' => $bot->openai_key]);
                                config(['openai.organization' => $bot->openai_org]);

                                // Transcripción de audio con Whisper (solo para generar respuesta)
                                $response = OpenAI::audio()->transcribe([
                                    'model' => 'whisper-1',
                                    'file' => fopen(storage_path('app/public/' . $file), 'r'),
                                    'response_format' => 'verbose_json',
                                    'timestamp_granularities' => ['segment', 'word']
                                ]);

                                $transcribedText = $response->text;

                                // Procesar la transcripción con el bot
                                $botIA = new BotIA();
                                $answer = $botIA->ask($transcribedText, $value['messages'][0]['from'], $bot->id, $bot->openai_key, $bot->openai_org, $bot->openai_assistant);

                                // Enviar respuesta al usuario por WhatsApp
                                $respuesta = new Whatsapp();
                                $response = $respuesta->sendText($value['messages'][0]['from'], $answer, $num->id_telefono, $app->token_api);

                                // Guardar solo el mensaje de respuesta
                                $reply = new Message();
                                $reply->wa_id = $value['contacts'][0]['wa_id'];
                                $reply->wam_id = $response["messages"][0]["id"];
                                $reply->phone_id = $num->id_telefono;
                                $reply->type = 'text';
                                $reply->outgoing = true;
                                $reply->body = $answer;
                                $reply->status = 'sent';
                                $reply->caption = '';
                                $reply->data = '';
                                $reply->save();

                                Webhook::dispatch($reply, false);
                            }
                        }
                    }

                    // Log and process other message types
                    else {
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

            // Return a success response if the process completes
            return response()->json([
                'success' => true,
                'data' => '',
            ], 200);
        } catch (Exception $e) {
            // Log the error details and trace for debugging purposes
            Log::error('Error al obtener mensajes6: ' . $e->getMessage());
            Log::error('Exception trace: ' . $e->getTraceAsString());
            Log::error('Contenido del cuerpo de la solicitud con error: ' . $request->getContent());

            // Return an error response with the exception message
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
