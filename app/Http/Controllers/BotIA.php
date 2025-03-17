<?php

namespace App\Http\Controllers;

use App\Models\Bot;
use OpenAI\Factory;
use App\Models\Lead;
use App\Models\User;
use App\Models\Thread;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use OpenAI\Responses\Threads\Runs\ThreadRunResponse;

class BotIA extends Controller
{
    public ?string $question = null;
    public ?string $answer = null;
    public ?string $error = null;


    // Método para manejar preguntas
    public function ask($question, $waId, $botId, $openai_key, $openai_org, $openai_assistant, $imageUrl)
    {
        Log::info('Pregunta: ' . $question . ', Imagen: ' . $imageUrl . ', Bot ID: ' . $botId . ', Usuario: ' . $waId);
        $this->question = $question;
        $bandera = true;

        // Obtener el bot y verificar si tiene un webhook habilitado
        $bot = Bot::find($botId);
        $webhookUrl = $bot->webhook_url ?? null;

        // Buscar si ya existe un hilo para este usuario y bot específico
        $thread = Thread::where('wa_id', $waId)
            ->where('bot_id', $botId)
            ->first();

        if (!$thread) {
            // Crear un nuevo hilo con OpenAI y guardarlo en la base de datos
            $threadRun = $this->createAndRunThread($openai_key, $openai_org, $openai_assistant);

            if (!$threadRun || empty($threadRun->threadId)) {
                Log::error('No se pudo crear un hilo en OpenAI.');
                return response()->json(['error' => 'No se pudo iniciar la conversación. Intenta de nuevo'], 500);
            }

            $thread = Thread::create([
                'wa_id' => $waId,
                'thread_id' => $threadRun->threadId,
                'bot_id' => $botId,
            ]);

            $bandera = false;
        }

        if ($webhookUrl) {
            Log::info('Webhook configurado para este bot.');
            // 🔹 Si hay un webhook configurado, enviar la solicitud a n8n
            try {
                $response = Http::post($webhookUrl, [
                    'message' => $question,
                    'image_url' => $imageUrl,
                    'thread_id' => $thread->thread_id,
                ]);

                Log::info('Respuesta de n8n:', $response->json());

                // Procesar la respuesta de n8n
                $n8nResponse = $response->json();
                $this->answer = $n8nResponse['answer'] ?? 'Lo siento, no entendí tu mensaje.';

                return $this->answer; // ✅ La función termina aquí y no sigue a los otros if
            } catch (\Exception $e) {
                Log::error('Error al enviar solicitud a n8n: ' . $e->getMessage());
                $this->answer = 'Hubo un problema al procesar tu mensaje.';
                return $this->answer;
            }
        }

        // 🔹 Si hay imagen y texto, procesar ambos
        if (!empty($imageUrl)) {
            Log::info('Procesando imagen y texto...');

            if (!$thread) {
                Log::error('Error: No se encontró un hilo válido antes de procesar la imagen.');
                return response()->json(['error' => 'No se pudo procesar la imagen correctamente.'], 500);
            }

            return $this->processImageAndText($imageUrl, $question, $botId, $bot->openai_key, $bot->openai_org, $bot->openai_assistant, $waId, $thread->thread_id);

        } elseif (!empty($question)) {
            Log::info('Procesando solo texto...');

            if ($bandera) {
                $threadRun = $this->continueThread($thread->thread_id, $openai_key, $openai_org, $openai_assistant);

                if (!$threadRun) {
                    Log::error('Error: No se pudo continuar el hilo en OpenAI.');
                    return response()->json(['error' => 'No se pudo continuar la conversación. Intenta nuevamente.'], 500);
                }
                // 🔹 Si NO hay un webhook, usar OpenAI directamente
            }

            return $this->loadAnswer($threadRun, $openai_key, $openai_org, $openai_assistant, $botId);
        } else {

            return response()->json(['error' => 'Debes enviar una pregunta o una imagen.'], 400);
        }
    }

    private function processImageAndText($imageUrl, $question, $botId, $openai_key, $openai_org, $openai_assistant, $waId, $threadId)
    {
        try {
            $openAI = (new Factory())
                ->withApiKey($openai_key)
                ->withOrganization($openai_org)
                ->withHttpHeader('OpenAI-Beta', 'assistants=v2')
                ->make();

            $bot = Bot::find($botId);


            // Verificar si la imagen es accesible
            if ($imageUrl) {
                $imageHeaders = @get_headers($imageUrl);
                if (!$imageHeaders || strpos($imageHeaders[0], '200') === false) {
                    Log::error('OpenAI no puede acceder a la imagen: ' . $imageUrl);
                    return 'Error: OpenAI no puede acceder a la imagen.';
                }
            }

            // **Esperar si hay un `Run` en curso antes de agregar mensajes**
            $runStatus = $openAI->threads()->runs()->list($threadId);
            foreach ($runStatus->data as $run) {
                if (in_array($run->status, ['queued', 'in_progress'])) {
                    Log::info('Esperando a que termine el run antes de agregar mensajes...');
                    sleep(3);
                }
            }

            // 🔹 **Crear el contenido a enviar**
            $content = [];

            if (!empty($question)) {
                Log::info('Se agregó la pregunta al contenido.');
                $content[] = ['type' => 'text', 'text' => $question];
            }
            if (!empty($imageUrl)) {
                Log::info('Se agregó la imagen al contenido.');
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]];
            }


            // Enviar mensaje con imagen y/o texto al asistente
            $messageResponse = $openAI->threads()->messages()->create(
                threadId: $threadId,
                parameters: [
                    'role' => 'user',
                    'content' => $content, // **✅ Ahora `content` nunca es `null`**
                ]
            );

            if (!$messageResponse) {
                throw new \Exception('Error al enviar el mensaje con imagen.');
            }

            // Ejecutar el asistente
            $run = $openAI->threads()->runs()->create(
                threadId: $threadId,
                parameters: [
                    'assistant_id' => $bot->openai_assistant,
                ]
            );

            if (!$run) {
                throw new \Exception('Error al ejecutar el asistente.');
            }

            // Esperar respuesta de OpenAI con un timeout extendido
            $timeout = 60; // Aumentado a 60 segundos
            $elapsed = 0;

            do {
                sleep(2);
                $elapsed += 2;
                $runStatus = $openAI->threads()->runs()->retrieve($threadId, $run->id);

                if ($elapsed >= $timeout) {
                    throw new \Exception('Timeout al procesar la imagen.');
                }
            } while (in_array($runStatus->status, ['queued', 'in_progress']));

            if ($runStatus->status !== 'completed') {
                throw new \Exception('Error al procesar la imagen con el asistente.');
            }

            // Obtener la respuesta final del asistente
            $messages = $openAI->threads()->messages()->list($threadId);

            if (!isset($messages->data[0]->content[0]->text->value)) {
                throw new \Exception('No se recibió respuesta.');
            }

            Log::info('Respuesta del asistente: ' . $messages->data[0]->content[0]->text->value);

            return $messages->data[0]->content[0]->text->value;

        } catch (\Exception $e) {
            Log::error('Error en processImageAndText: ' . $e->getMessage());
            return 'Ocurrió un error al procesar la imagen: ' . $e->getMessage();
        }
    }


    // Método para crear y ejecutar un nuevo hilo
    // Método para crear y ejecutar un nuevo hilo
    private function createAndRunThread($openai_key, $openai_org, $openai_assistant)
    {


        // Cambiar dinámicamente las credenciales de OpenAI
        config(['openai.api_key' => $openai_key]);
        config(['openai.organization' => $openai_org]);

        return OpenAI::threads()->createAndRun([
            'assistant_id' => $openai_assistant,
            'thread' => [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $this->question,
                    ],
                ],
            ],
        ]);
    }

    // Método para continuar un hilo existente
    private function continueThread($threadId, $openai_key, $openai_org, $openai_assistant)
    {
        // Cambiar dinámicamente las credenciales de OpenAI
        config(['openai.api_key' => $openai_key]);
        config(['openai.organization' => $openai_org]);

        // Primero, envía un mensaje al hilo existente
        OpenAI::threads()->messages()->create(
            $threadId, // Pasar el threadId como string
            [
                'role' => 'user',
                'content' => $this->question,
            ]
        );

        // Luego, crea un run para continuar con el hilo
        return OpenAI::threads()->runs()->create(
            $threadId, // Pasar el threadId como string
            [
                'assistant_id' => $openai_assistant,
            ]
        );
    }


    // Método para cargar la respuesta desde el hilo
    // Método para cargar la respuesta desde el hilo
    private function loadAnswer($threadRun, $openai_key, $openai_org, $openai_assistant, $botId)
    {
        // Cambiar dinámicamente las credenciales de OpenAI
        config(['openai.api_key' => $openai_key]);
        config(['openai.organization' => $openai_org]);


        // Limitar el número de intentos para evitar bucles infinitos
        $maxAttempts = 10;
        $attempts = 0;

        if (!$threadRun || !isset($threadRun->status)) {
            Log::error('OpenAI Request failed: No se pudo obtener el estado del threadRun.');
            return 'Error al comunicarse con OpenAI. Intenta más tarde.';
        }


        while (in_array($threadRun->status, ['queued', 'in_progress']) && $attempts < $maxAttempts) {
            sleep(2);
            $threadRun = OpenAI::threads()->runs()->retrieve($threadRun->threadId, $threadRun->id);
            $attempts++;
        }

        if ($threadRun->status !== 'completed' && $threadRun->status !== 'requires_action') {
            Log::error('OpenAI Request failed, status: ' . $threadRun->status);
            return 'Error en la generación de respuesta. Intenta de nuevo.';
        }

        $isResponsePrompt = false;
        $dataResponse = '';
        if (isset($threadRun->status) && $threadRun->status === 'requires_action') {
            $tools_to_call = $threadRun->requiredAction->submitToolOutputs->toolCalls ?? [];
            $tools_output_array = []; // Initialize outside the loop

            foreach ($tools_to_call as $tool_call) {
                if ($tool_call->function->name === 'create_lead') {
                    $respuesta = $this->handleFunctionCall($tool_call->function->name, json_decode($tool_call->function->arguments, true), $botId);
                    $tools_output_array = [
                        'tool_outputs' => [
                            [
                                'tool_call_id' => $tool_call->id,  // Cambiado de 'tools_call_id' a 'tool_call_id'
                                'output' => $respuesta,
                            ],
                        ]
                    ];
                } else if ($tool_call->function->name === 'get_prompt_response') {
                    $prompt = $this->CreatePrompt($tool_call->function->name, json_decode($tool_call->function->arguments, true), $botId);
                    $isResponsePrompt = true;
                    $dataResponse = $prompt;
                    $tools_output_array = [
                        'tool_outputs' => [
                            [
                                'tool_call_id' => $tool_call->id,  // Cambiado de 'tools_call_id' a 'tool_call_id'
                                'output' => 'Prompt creado correctamente',
                            ],
                        ]
                    ];
                }
            }
            // Submit all tool outputs at once after the loop
            if (!empty($tools_output_array)) {
                // Pasar como un objeto, no como un arreglo
                OpenAI::threads()->runs()->submitToolOutputs(
                    $threadRun->threadId,
                    $threadRun->id,
                    $tools_output_array
                );

                $attempts = 0;
                while (in_array($threadRun->status, ['completed', 'failed', 'requires_action']) && $attempts < $maxAttempts) {
                    // Recupera el estado actual de la tarea
                    $threadRun = OpenAI::threads()->runs()->retrieve(
                        $threadRun->threadId,
                        $threadRun->id
                    );
                    // Espera 10 segundos antes de la próxima verificación
                    sleep(5);
                    $attempts++;
                }

                // Imprime el estado final después de que se complete el proceso
                \Log::info("Estado final de la tarea: " . $threadRun->status);
            }
        }

        $messageList = OpenAI::threads()->messages()->list(
            $threadRun->threadId,
        );

        if ($isResponsePrompt) {
            // Si la respuesta es un prompt, guardar una cadena con el identificador
            Log::info('Respuesta generada como prompt: ' . $dataResponse);
            $this->answer = 'PROMPT:' . $dataResponse;
        } else {
            // Si no, guardar el mensaje normal
            $answer = $messageList->data[0]->content[0]->text->value ?? null;

            if (!$answer) {
                Log::warning('No se recibió respuesta de OpenAI.');
                return 'Lo siento, no tengo una respuesta en este momento.';
            }

            return $answer;
        }
    }

    public function handleFunctionCall($functionName, $parameters, $botId)
    {
        try {
            // Use firstOrCreate to avoid duplicate entry issues
            Lead::firstOrCreate(
                ['email' => $parameters['email']], // Unique field to check
                [
                    'bot_id' => $botId,
                    'nombre' => $parameters['nombre'],
                    'telefono' => $parameters['telefono'],
                    'detalles' => $parameters['detalles'],
                    'calificacion' => $parameters['calificacion'],
                    'estado' => 'nuevo',
                ]
            );

            return 'tus datos han sido guardados correctamente';

        } catch (\Illuminate\Database\QueryException $exception) {
            \Log::error('Failed to create lead: ' . $exception->getMessage());
            // Additional handling if needed, like returning a specific response
        }

        return 'Ha ocurrido un error al guardar tus datos';
    }
}
