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
    // Log to confirm that processWebhook has been called with the incoming request payload
    Log::info('processWebhook llamado con payload: ' . $request->getContent());

    try {
        // Decode the incoming request content
        $bodyContent = json_decode($request->getContent(), true);
        $value = $bodyContent['entry'][0]['changes'][0]['value'];

        // Log the value to see what is being received
        Log::info('Valor de $value: ' . json_encode($value));

        // Check if there are statuses to process
        if (!empty($value['statuses'])) {
            Log::info('Estado recibido: ' . json_encode($value['statuses'][0]));
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
            Log::info('Mensaje recibido: ' . json_encode($value['messages'][0]));

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
