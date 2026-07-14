<?php
/**
 * gCore Framework - Simple Message Broker Client Example
 * 
 * This example demonstrates how to interact with the MessageBroker API.
 */

// Configuration
$apiBase = 'http://localhost:8080';
$apiKey = 'admin-key-12345'; // Replace with your admin API key from server output

// Headers for all requests
$headers = [
    'Content-Type: application/json',
    'X-API-Key: ' . $apiKey
];

echo "gCore MessageBroker Client Example\n";
echo "=================================\n\n";

// 1. Create a queue
echo "1. Creating a test queue...\n";
$createQueueResult = apiRequest('POST', '/queues', [
    'name' => 'test_queue'
]);
echo "Result: " . json_encode($createQueueResult, JSON_PRETTY_PRINT) . "\n\n";

// 2. Publish a message
echo "2. Publishing a message to the queue...\n";
$publishResult = apiRequest('POST', '/messages/test_queue', [
    'message' => 'Hello from the client example!'
]);
echo "Result: " . json_encode($publishResult, JSON_PRETTY_PRINT) . "\n\n";

// 3. List all queues
echo "3. Listing all queues...\n";
$listQueuesResult = apiRequest('GET', '/queues');
echo "Result: " . json_encode($listQueuesResult, JSON_PRETTY_PRINT) . "\n\n";

// 4. Consume a message
echo "4. Consuming a message from the queue...\n";
$consumeResult = apiRequest('GET', '/messages/test_queue');
echo "Result: " . json_encode($consumeResult, JSON_PRETTY_PRINT) . "\n\n";

// 5. Delete the queue (optional, commented out to prevent accidental deletion)
// echo "5. Deleting the test queue...\n";
// $deleteResult = apiRequest('DELETE', '/queues/test_queue');
// echo "Result: " . json_encode($deleteResult, JSON_PRETTY_PRINT) . "\n\n";

echo "Example completed successfully!\n";

/**
 * Helper function to make API requests
 * 
 * @param string $method HTTP method (GET, POST, DELETE)
 * @param string $endpoint API endpoint
 * @param array $data Request data (for POST)
 * @return array Response data
 */
function apiRequest(string $method, string $endpoint, array $data = []): array {
    global $apiBase, $headers;
    
    $ch = curl_init($apiBase . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => 'CURL Error: ' . $error];
    }
    
    // Try to decode JSON response
    $decoded = json_decode($response, true);
    if ($decoded === null && $response !== '') {
        return ['error' => 'Invalid JSON response', 'raw' => $response, 'status' => $statusCode];
    }
    
    return $decoded ?: ['status' => $statusCode, 'message' => 'Empty response'];
}