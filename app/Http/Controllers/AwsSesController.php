<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\NewsletterStatistic;
use App\Models\UserEmail;
use App\Models\Contacto;

class AwsSesController extends Controller
{
    public function handleNotification(Request $request)
    {
        $payload = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('JSON inválido recibido: ' . json_last_error_msg());
            return response()->json(['error' => 'Invalid JSON'], 400);
        }

        Log::info('Evento recibido de AWS SES', ['payload' => $payload]);

        // Validar el tipo de evento
        if (!isset($payload['Type'])) {
            Log::error('Evento malformado recibido de AWS SES', ['payload' => $payload]);
            return response()->json(['error' => 'Malformed event'], 400);
        }

        switch ($payload['Type']) {
            case 'Notification':
                $message = json_decode($payload['Message'], true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Es una notificación de prueba o mensaje malformado
                    Log::info('Notificación de prueba recibida', ['Message' => $payload['Message']]);
                    return response()->json(['status' => 'test notification received']);
                }

                // Procesar eventos reales
                if (isset($message['eventType'])) {
                    $this->processEventType($message);
                } else {
                    Log::error('Mensaje sin eventType', ['Message' => $message]);
                }
                break;

            case 'SubscriptionConfirmation':
                // Manejar la confirmación de suscripción
                $this->confirmSubscription($payload['SubscribeURL']);
                break;

            default:
                Log::warning('Tipo de evento no manejado', ['Type' => $payload['Type']]);
        }

        return response()->json(['status' => 'success']);
    }


    private function confirmSubscription($subscribeUrl)
    {
        $client = new \GuzzleHttp\Client();
        try {
            $response = $client->get($subscribeUrl);
            Log::info('Suscripción confirmada exitosamente.', ['response' => $response->getBody()->getContents()]);
        } catch (\Exception $e) {
            Log::error('Error al confirmar la suscripción.', ['error' => $e->getMessage()]);
        }
    }

    private function processEventType($message)
    {
        $email = $message['mail']['destination'][0] ?? null;
        $eventType = $message['eventType'];
        $messageId = $message['mail']['messageId'] ?? null;
        $newsletterId = null;
        $sourceIp = $message['mail']['tags']['ses:source-ip'][0] ?? null;
        $browser = null;
        $operatingSystem = null;

        // Extraer `newsletter_id` desde las cabeceras de AWS SES
        if (isset($message['mail']['headers'])) {
            foreach ($message['mail']['headers'] as $header) {
                if ($header['name'] === 'X-SES-MESSAGE-TAGS' && str_contains($header['value'], 'newsletter_id=')) {
                    $newsletterId = explode('=', $header['value'])[1] ?? null;
                }
            }
        }

        if (!$email || !$eventType || !$messageId) {
            Log::warning('Evento malformado recibido.', ['message' => $message]);
            return;
        }

        // Si el evento es "Open", extraer datos adicionales
        if ($eventType === 'Open' && isset($message['open'])) {
            $userAgent = $message['open']['userAgent'] ?? null;
            $sourceIp = $message['open']['ipAddress'] ?? $sourceIp;

            if ($userAgent) {
                // Utilizar una librería para parsear el User-Agent
                $browser = $this->getBrowserFromUserAgent($userAgent);
                $operatingSystem = $this->getOSFromUserAgent($userAgent);
            }
        }

        // Intentar encontrar un registro existente por `message_id`
        $statistic = NewsletterStatistic::where('message_id', $messageId)->first();

        if ($statistic) {
            // Actualizar el estado si ya existe
            $statistic->update([
                'status' => $eventType,
                'browser' => $browser,
                'operating_system' => $operatingSystem,
                'source_ip' => $sourceIp,
                'updated_at' => now(),
            ]);
            Log::info("Estado actualizado para el mensaje: $messageId, nuevo estado: $eventType");
        } else {
            // Crear un nuevo registro si no existe
            NewsletterStatistic::create([
                'email' => $email,
                'message_id' => $messageId,
                'status' => $eventType,
                'newsletter_id' => $newsletterId,
                'browser' => $browser,
                'operating_system' => $operatingSystem,
                'source_ip' => $sourceIp,
            ]);
            Log::info("Nuevo registro creado para el mensaje: $messageId, estado: $eventType");
        }
    }

    /**
     * Extrae el navegador desde el User-Agent.
     */
    private function getBrowserFromUserAgent($userAgent)
    {
        if (strpos($userAgent, 'Firefox') !== false)
            return 'Firefox';
        if (strpos($userAgent, 'Chrome') !== false)
            return 'Chrome';
        if (strpos($userAgent, 'Safari') !== false)
            return 'Safari';
        if (strpos($userAgent, 'Edge') !== false)
            return 'Edge';
        if (strpos($userAgent, 'Opera') !== false)
            return 'Opera';
        return 'Desconocido';
    }

    /**
     * Extrae el sistema operativo desde el User-Agent.
     */
    private function getOSFromUserAgent($userAgent)
    {
        if (strpos($userAgent, 'Windows') !== false)
            return 'Windows';
        if (strpos($userAgent, 'Macintosh') !== false)
            return 'MacOS';
        if (strpos($userAgent, 'Linux') !== false)
            return 'Linux';
        if (strpos($userAgent, 'Android') !== false)
            return 'Android';
        if (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false)
            return 'iOS';
        return 'Desconocido';
    }

}
