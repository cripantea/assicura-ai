<?php

namespace App\Filament\Pages;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;
use Smalot\PdfParser\Parser;

class EmailGenerator extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected string $view = 'filament.pages.email-generator';


    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-document-text';
    public $pdf=[];
    public $body='';
    public $email='';
    public $subject='';
    protected function getFormSchema(): array
    {
        return [
            FileUpload::make('pdf')
                ->label('Insurance PDF')
                ->acceptedFileTypes(['application/pdf'])
                ->directory('tmp') // optional
                ->required()
                ->extraAttributes([
                    'class' => 'custom-double-height', // <-- Apply custom class here
                ])
                ->multiple(false),

//            Textarea::make('body')
//                ->label('Draft (editable)')
//                ->live()
//                ->rows(10),

            TextInput::make('email')
                ->email()
                ->live()
                ->label('Customer email'),

            TextInput::make('subject')
            ->live(),
        ];
    }


    public function generate(): void
    {
//        $this->validate([
//            'attachment' => 'required',
//        ]);

        $file = reset($this->pdf);

        try {
            // --- PASSO 1: ESTRAZIONE TESTO DAL FILE ---
            // Nota: Questo parser base funziona solo per PDF testuali.
            // Per immagini, PDF scannerizzati (OCR) o file Word, è necessario un approccio più avanzato.
            // La soluzione migliore è usare un modello multimodale come GPT-4o che può analizzare le immagini direttamente.
            $parser = new Parser();
            $pdfParsed = $parser->parseFile($file->getRealPath());
            $documentText = $pdfParsed->getText();

            if (empty($documentText)) {
                Notification::make()->title('Estrazione fallita')->body('Impossibile estrarre testo dal file. Potrebbe essere un\'immagine o un PDF scannerizzato.')->danger()->send();
                return;
            }

            // --- PASSO 2: CHIAMATA API PER ESTRAZIONE DATI IN JSON ---
            $extractedData = $this->extractDataWithAI($documentText);

            if (!$extractedData) {
                // La notifica di errore è già inviata da extractDataWithAI
                return;
            }

            // --- PASSO 3: RENDERING DEL TEMPLATE EMAIL ---
            // $this->email = $extractedData['cliente']['email'] ?? '';
            // $emailContent = $this->renderEmailTemplate($extractedData);

            $this->subject = $extractedData['Subject'];
            $this->body = str_replace('%0D%0A', chr(13), $extractedData['Body']);
            $this->email = $extractedData['Email'];

            Notification::make()->title('Bozza Generata')->success()->send();

            // NUOVO: Esegui il codice JavaScript per attivare il mailto:
            $this->js('
                // Incapsula la logica per consentire l\'uso di \'var\' e codice multilinea.
                (function () {
                    var email = $wire.email;
                    var subject = $wire.subject;
                    var body = $wire.body;

                    if (email && subject && body) {
                        // Codifica i componenti dell\'URL per mailto:
                        var mailtoUrl = "mailto:" + encodeURIComponent(email) +
                                        "?subject=" + encodeURIComponent(subject) +
                                        "&body=" + encodeURIComponent(body);

                        window.open(mailtoUrl, "_self");
                    } else {
                        console.error("Dati email mancanti per l\'invio automatico.");
                    }
                })();
            ');
        } catch (\Throwable $e) {
            Notification::make()->title('Errore Inatteso')
                ->body($e->getMessage())->danger()->send();
        }
    }

    private function extractDataWithAI(string $text): ?array
    {
        $apiKey = env('OPENAI_API_KEY');
        $client = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30.0,    // Tempo totale massimo in secondi (es. 60 secondi)
            'connect_timeout' => 15.0,
        ]);

        // Prompt di sistema che istruisce il modello a estrarre i dati in formato JSON.
        // Questo è il cuore della nuova logica.
        $systemPrompt = "OBIETTIVO Quando l'utente carica un preventivo (PDF/immagine/Word),
            estrai i dati chiave e genera un'email professionale e sintetica (max 3 righe)
            basata su template indicato sotto (SUBJECT E BODY).
            Estrai e normalizza i seguenti dati:
            - Contraente: nome, cognome, CF/P.IVA, contatti
            - Rischio: tipo (auto/moto/immobile/rcpro/altro) + dettagli
            - Compagnia, prodotto, garanzie principali (massimali, franchigie)
            - Premio (totale, frazionamento, imposte), decorrenza/scadenza
            - Esclusioni/note e documenti richiesti
            Usa OCR se necessario, formato date gg/mm/aaaa.
            Se un dato manca usa “— (non presente)”. Nessuna invenzione.

            con in dati sopra Genera un json con 3 chiavi, Subject, Body ed Email de contraente (se c'è). Il subject ha la seguente struttura:
            SUBJECT: “Preventivo {ramo} - {cognome} - {compagnia} - €{premio}”
            Il body:
            BODY:
            Ciao {cliente.nome},
                in allegato il preventivo {ramo} con {compagnia}, premio €{premio.totale} ({frazionamento}) per {rischio.categoria} - {rischio.dettagli}. Decorrenza {decorrenza.da}→{decorrenza.a}. Rispondi “CONFERMO” per confermare la polizza o chiedi modifiche su massimali/franchigie.
                    PS: Allego documenti e preventivo.

                    Il body e il subject vengono inseriti in un link <a href=mailto: ... dunque le 2 stringhe devono essere compatibili, senza \n o \t ma con i relativi %0D%0A

                    ";

        try {
            $response = $client->post('chat/completions', [
                'json' => [
                    'model' => 'gpt-4o', // Consigliato per la sua precisione con JSON e istruzioni complesse
                    'response_format' => ['type' => 'json_object'], // Forza l'output in formato JSON
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => "Estrai i dati dal seguente testo del preventivo:\n\n" . $text],
                    ],
                    'temperature' => 0.1,
                ],
            ]);

            $result = json_decode($response->getBody(), true);
            $jsonString = $result['choices'][0]['message']['content'] ?? '{}';

            return json_decode($jsonString, true);

        } catch (\Throwable $e) {
            Notification::make()->title('Chiamata AI fallita')
                ->body("Impossibile comunicare con l'API OpenAI: " . $e->getMessage())->danger()->send();
            return null;
        }
    }

    private function renderEmailTemplate(array $data): array
    {
        $templatePath = 'email_template.txt';
        if (Storage::disk('local')->exists($templatePath)) {
            $template = Storage::disk('local')->get($templatePath);
        } else {
            // Template di default se il file non esiste
            $template = <<<'TEMPLATE'
                OGGETTO:
                Preventivo {polizza.ramo} – {cliente.cognome} – {polizza.compagnia} – €{premio.totale}

                CORPO:
                Ciao {cliente.nome},

                ti inoltro il preventivo {polizza.ramo} con {polizza.compagnia} – prodotto {polizza.prodotto}.

                Ecco una sintesi rapida dei punti chiave:
                - **Rischio Assicurato:** {rischio.categoria} – {rischio.dettagli}
                - **Premio Totale Annuo:** €{premio.totale} ({premio.frazionamento})
                - **Periodo di Validità:** dal {decorrenza.da} al {decorrenza.a}

                **Garanzie Incluse:**
                {#garanzie}
                - **{nome}**
                  - Massimale: {massimale}
                  - Franchigia/Scoperto: {franchigia}
                {/garanzie}

                **Note e Esclusioni Rilevanti:**
                {esclusioni_note|Dato non presente}

                **Documenti per l'Emissione:**
                {#documenti_richiesti}
                    - {.}
                {/documenti_richiesti}

                Se vuoi procedere, è sufficiente rispondere a questa email con "CONFERMO". Ti contatterò per la firma digitale.
                Possiamo anche personalizzare ulteriormente la polizza modificando massimali o franchigie per ottimizzare il premio secondo le tue esigenze.

                Resto a tua completa disposizione per qualsiasi chiarimento.

                Un saluto,

                [Il Tuo Nome]
                [La Tua Agenzia/Società]
                [Tuo Telefono] – [Tua Email]

                {#alternative}
                    ---
                **PS: Opzioni Alternative**
                {alternative}
                {/alternative}
                TEMPLATE;
        }

        // Separazione oggetto e corpo
        list($subjectTemplate, $bodyTemplate) = explode('CORPO:', $template, 2);
        $subjectTemplate = str_replace('OGGETTO:', '', $subjectTemplate);

        // Funzione di rendering
        $render = function ($tpl, $data) use (&$render) {
            return preg_replace_callback('/{(\/)?(#)?([\w\.]+)(\|([^}]+))?}/', function ($matches) use ($data, $render, $tpl) {
                list(, $isClosing, $isSection, $key, , $default) = array_pad($matches, 6, null);

                $default = $default ?? '---';
                $value = \Illuminate\Support\Arr::get($data, $key, $default);

                if ($isSection) {
                    if (is_array($value) && !empty($value)) {
                        $content = '';
                        $sectionRegex = "/{#" . preg_quote($key) . "}(.*?{\/". preg_quote($key) . "})/s";
                        preg_match($sectionRegex, $tpl, $sectionMatches);
                        $sectionTemplate = preg_replace("/(^{#". preg_quote($key) . "}|{\/". preg_quote($key) . "}$)/s", '', $sectionMatches[1] ?? '');

                        foreach ($value as $item) {
                            // Sostituisce {.} con l'item stesso se è uno scalare, o renderizza l'item se è un array
                            $itemData = is_array($item) ? $item : ['.' => $item];
                            $content .= $render($sectionTemplate, array_merge($data, $itemData));
                        }
                        return $content;
                    }
                    return ''; // Se la sezione non ha dati, rimuovila
                }

                if (is_array($value)) return ''; // Non stampare "Array"
                return $value ?: $default;
            }, $tpl);
        };

        $finalBody = trim($render($bodyTemplate, $data));
        $finalSubject = trim($render($subjectTemplate, $data));

        // Rimuovi sezioni vuote
        $finalBody = preg_replace('/{#[\w\.]+}.*?{\/[\w\.]+}/s', '', $finalBody);

        return ['subject' => $finalSubject, 'body' => $finalBody];
    }

    public function send(): void
    {
        if (! $this->email || ! $this->body) {
            Notification::make()->title('Email and body are required')->danger()->send();
            return;
        }

        try {
            Mail::raw($this->body, function ($message) {
                $message->to($this->email)
                    ->from('cripantea@gmail.com', 'er grande gigi')
                    ->subject($this->subject);
            });
            Notification::make()->title('Email sent')->success()->send();

        } catch (\Throwable $e) {
            Notification::make()->title('Send failed')
                ->body($e->getMessage())->danger()->send();
        }
    }

}
