<?php

//
// composer require smalot/pdfparser
//

header('Access-Control-Allow-Headers: Access-Control-Allow-Origin, Content-Type, Authorization');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET,PUT,POST,DELETE,PATCH,OPTIONS');
header('Content-type: application/json');

require __DIR__ . '/vendor/autoload.php';  // still needed for PdfParser

use Smalot\PdfParser\Parser as PdfParser;

$apiKey = 'sk-proj-';  // replace with your key

//--
//-- Standard PHP Upload Code 
//--
$targetDir = __DIR__ . "/uploads/";
if (!file_exists($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (isset($_FILES['file'])) {
   $file = $_FILES['file'];
   $filename = basename($file['name']);
   $uid = $_POST['uid'];
   $targetFilePath = $targetDir . $filename;
} else {
  print_r($_RESPONSE);
}
move_uploaded_file($file['tmp_name'], $targetFilePath);



function extractTextFromPdf(string $path): string
{
    $parser = new PdfParser();
    $pdf    = $parser->parseFile($path);
    $text   = '';
    foreach ($pdf->getPages() as $page) {
        $text .= $page->getText() . "\n\n";
    }
    return $text;
}

$patientInfoFunction = [
    'name'        => 'extract_patient_info',
    'description' => 'Extracts key personal fields from a health report.',
    'parameters'  => [
        'type'       => 'object',
        'properties' => [
            'name'               => ['type'=>'string'],
            'patient_id'         => ['type'=>'string'],
            'mrn'                => ['type'=>['string','null']],
            'birth_date'         => ['type'=>'string','format'=>'date'],
            'age'                => ['type'=>'integer'],
            'gender'             => ['type'=>'string'],
            'address'            => ['type'=>'string'],
            'phone'              => [
                'type'=>'object',
                'properties'=>[
                    'home'=>['type'=>['string','null']],
                    'work'=>['type'=>['string','null']],
                    'cell'=>['type'=>['string','null']],
                ]
            ],
            'email'              => ['type'=>'string'],
            'provider'           => ['type'=>'string'],
            'referring_provider' => ['type'=>'string'],
            'print_date'         => ['type'=>'string','format'=>'date'],
        ],
        'required'=>['name','patient_id','birth_date','age','gender','address','email']
    ]
];


function callExtraction(string $apiKey, string $text, array $fnSchema): array
{
    $body = [
        'model'        => 'gpt-4o-mini',
        'temperature'  => 0.0,
        'messages'     => [
            ['role'=>'system','content'=>'You are a medical data extractor.'],
            ['role'=>'user',  'content'=> $text],
        ],
        'functions'       => [$fnSchema],
        'function_call'   => ['name'=>$fnSchema['name']],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($body),
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        error_log("OpenAI cURL error: $err");
        return [];
    }

    $data = json_decode($resp, true);
    $call = $data['choices'][0]['message']['function_call'] ?? null;
    if (!$call || !isset($call['arguments'])) {
        return [];
    }

    return json_decode($call['arguments'], true);
}

$text = extractTextFromPdf($targetFilePath);
$info = callExtraction($apiKey, $text, $patientInfoFunction);
print_r($info);

?>