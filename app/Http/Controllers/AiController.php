<?php

namespace App\Http\Controllers;

class AiController extends Controller
{
    public function index()
    {

    }

    public function generate(Request $request)
    {

        dd($request->all());
        $data = $request->validate([
            'file' => ['required','file','mimes:pdf','max:20480'], // 20 MB
        ]);

        // store temporarily
        $path = $request->file('file')->store('ai/tmp');
        $abs  = Storage::path($path);

        try {
            // 1) Upload PDF to OpenAI Files
            $upload = Http::withToken(env('OPENAI_API_KEY'))
                ->attach('file', fopen($abs, 'r'), basename($abs))
                ->post('https://api.openai.com/v1/files', [
                    'purpose' => 'assistants',
                ])
                ->throw()
                ->json();

            $fileId = $upload['id'];

            // 2) Ask Responses API to read the file and draft the email
            $payload = [
                'model' => 'gpt-4o',                     // or gpt-4o-mini (cheaper)
                'tools' => [['type' => 'file_search']],  // enables retrieval
                'attachments' => [[
                    'file_id' => $fileId,
                    'tools'   => [['type' => 'file_search']],
                ]],
                'input' => trim("
                    You are an insurance assistant.
                    Read the attached PDF (insurance policy) and draft a concise,
                    customer-friendly email that includes:
                    - policy number, insured party, start/end dates
                    - key coverages and notable exclusions
                    - premium/fees and due date(s)
                    - required next steps (e.g., docs, signature, payment link placeholder)
                    - polite professional closing
                    IMPORTANT: Output ONLY the email body in plain text (no markdown).
                "),
                'max_output_tokens' => 800,
                'temperature' => 0.3,
            ];

            $resp = Http::withToken(env('OPENAI_API_KEY'))
                ->post('https://api.openai.com/v1/responses', $payload)
                ->throw()
                ->json();

            // Responses API convenience field:
            $draft = $resp['output_text'] ?? '';

            return response()->json(['emailDraft' => $draft]);
        } finally {
            // cleanup temp file
            Storage::delete($path);
        }
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'to'      => ['required','email'],
            'subject' => ['required','string','max:200'],
            'body'    => ['required','string'],
        ]);

        // Brevo transactional API
        $payload = [
            'sender' => ['email' => 'no-reply@yourdomain.com', 'name' => 'Your Company'],
            'to'     => [['email' => $data['to']]],
            'subject' => $data['subject'],
            'textContent' => $data['body'],
        ];

        Http::withHeaders(['api-key' => env('BREVO_API_KEY')])
            ->post('https://api.brevo.com/v3/smtp/email', $payload)
            ->throw();

        return response()->json(['ok' => true]);
    }
}
