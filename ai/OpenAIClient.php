<?php
require_once __DIR__ . '/../config/config.php';

class OpenAIClient {
    private $apiKey;

    public function __construct($conn = null) {
        // Try to get API key from database first
        $key = null;
        if ($conn) {
            $result = $conn->query("SELECT openai_api_key FROM company_settings LIMIT 1");
            if ($result && $row = $result->fetch_assoc()) {
                $key = $row['openai_api_key'] ?? null;
            }
        }
        
        // Fallback to environment variable if not in database
        if (!$key) {
            $key = getenv('OPENAI_API_KEY');
        }
        
        if (!$key) {
            throw new Exception('OpenAI API Key is not set. Please add it in Settings page.');
        }
        $this->apiKey = $key;
    }

    public function extractInvoicesFromImage(string $imagePath): array {
        if (!file_exists($imagePath)) {
            throw new Exception('Image not found: ' . $imagePath);
        }
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            throw new Exception('Failed to read image: ' . $imagePath);
        }
        $base64 = base64_encode($imageData);

        $prompt = [
            'role' => 'user',
            'content' => [
                [ 'type' => 'text', 'text' => $this->getSystemExtractionPrompt() ],
                [ 'type' => 'image_url', 'image_url' => [ 'url' => 'data:image/*;base64,' . $base64 ] ],
            ],
        ];

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [ $prompt ],
            'temperature' => 0.0,
            'response_format' => [ 'type' => 'json_object' ],
            'max_tokens' => 4000,
        ];

        $response = $this->postJson('https://api.openai.com/v1/chat/completions', $payload);
        if (!isset($response['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response from OpenAI API.');
        }
        $jsonText = $response['choices'][0]['message']['content'];
        $data = json_decode($jsonText, true);
        if ($data === null) {
            throw new Exception('Failed to decode JSON from OpenAI response.');
        }
        if (!isset($data['invoices']) || !is_array($data['invoices'])) {
            throw new Exception('OpenAI response missing "invoices" array.');
        }
        return $data;
    }

    private function getSystemExtractionPrompt(): string {
        return trim(<<<'TXT'
Return ONLY strict JSON with this schema (no extra keys):
{
  "rawText": "string",
  "invoices": [
    {
      "invoiceNumber": null,
      "displayDate": "YYYY-MM-DD | null",
      "customerName": "string",
      "items": [
        {
          "itemName": "string",
          "quantity": number,
          "weight": number | 0,
          "unit": "PCS|KG|GM|LTR|MTR|OTHER",
          "rate": number,
          "amount": number,
          "vendorCodeRaw": "string | null",
          "vendorShortcut": "string | null",
          "batchMarker": "string | null"
        }
      ],
      "notes": "string | null"
    }
  ]
}

Rules:
- The image is a hand-written register page containing multiple sales entries.
- Return rawText as a faithful, line-by-line transcription first.
- Parse it into multiple invoices; each line/block is one invoice when applicable.
- ALWAYS set invoiceNumber to null (invoice numbers will be auto-generated).
- Compute amount as quantity*rate or weight*rate when clear; otherwise best estimate.
- Do NOT include currency symbols. Use decimals.
- Never include comments; output must be valid JSON only.

CRITICAL - Vendor shortcuts and batch markers:
- Vendor shortcuts are short codes like "ib", "ar", "bindi", "v1", etc.
- Batch markers are suffixes that indicate date: "p" (previous/yesterday), "pp" (previous-previous/day before yesterday)
- Examples:
  * "ib" → vendorCodeRaw: "ib", vendorShortcut: "ib", batchMarker: null (means today's inventory)
  * "ibp" → vendorCodeRaw: "ibp", vendorShortcut: "ib", batchMarker: "p" (means yesterday's inventory)
  * "ibpp" → vendorCodeRaw: "ibpp", vendorShortcut: "ib", batchMarker: "pp" (means day-before-yesterday's inventory)
  * "v1" → vendorCodeRaw: "v1", vendorShortcut: "v1", batchMarker: null (means today's inventory)
  * "v1p" → vendorCodeRaw: "v1p", vendorShortcut: "v1", batchMarker: "p" (means yesterday's inventory)
  * "bindipp" → vendorCodeRaw: "bindipp", vendorShortcut: "bindi", batchMarker: "pp" (means day-before-yesterday's inventory)
- ALWAYS extract ALL THREE fields: vendorCodeRaw (full code as written), vendorShortcut (vendor part only), batchMarker (date marker or null)
- If no date marker is present, batchMarker should be null (meaning today's inventory)
- Be very careful to separate vendor shortcuts from batch markers correctly
TXT);
    }

    private function postJson(string $url, array $payload): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('cURL error: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($result, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) ? json_encode($decoded) : $result;
            throw new Exception('OpenAI API HTTP ' . $status . ': ' . $detail);
        }
        return $decoded;
    }
}
?>


