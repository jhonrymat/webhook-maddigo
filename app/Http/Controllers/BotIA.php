<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Thread;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Auth;
use OpenAI\Responses\Threads\Runs\ThreadRunResponse;

class BotIA extends Controller
{
    public ?string $question = null;
    public ?string $answer = null;
    public ?string $error = null;


    // Método para manejar preguntas
    public function ask($question, $waId, $botId, $openai_key, $openai_org, $openai_assistant)
    {
        $this->question = $question;

        // Buscar si ya existe un hilo para este usuario y bot específico
        $thread = Thread::where('wa_id', $waId)
            ->where('bot_id', $botId)
            ->first();

        if ($thread) {
            // Si existe un hilo, usar el hilo existente
            $threadRun = $this->continueThread($thread->thread_id, $openai_key, $openai_org, $openai_assistant);
        } else {
            // Si no existe un hilo, crear uno nuevo
            $threadRun = $this->createAndRunThread($openai_key, $openai_org, $openai_assistant);

            // Guardar el nuevo hilo en la base de datos asociado al bot
            Thread::create([
                'wa_id' => $waId,
                'thread_id' => $threadRun->threadId,
                'bot_id' => $botId,  // Relacionar el hilo con el bot correcto
            ]);
        }

        // Cargar la respuesta del hilo
        $this->loadAnswer($threadRun, $openai_key, $openai_org, $openai_assistant);

        return $this->answer;
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
    private function loadAnswer($threadRun, $openai_key, $openai_org, $openai_assistant)
    {

        // Cambiar dinámicamente las credenciales de OpenAI
        config(['openai.api_key' => $openai_key]);
        config(['openai.organization' => $openai_org]);

        while (in_array($threadRun->status, ['queued', 'in_progress'])) {
            $threadRun = OpenAI::threads()->runs()->retrieve(
                threadId: $threadRun->threadId,
                runId: $threadRun->id,
            );
        }

        if ($threadRun->status !== 'completed') {
            $this->error = 'Request failed, please try again';
            return;
        }

        $messageList = OpenAI::threads()->messages()->list(
            threadId: $threadRun->threadId,
        );

        // Asigna la respuesta obtenida del mensaje
        $this->answer = $messageList->data[0]->content[0]->text->value ?? 'No answer received';
    }
}
