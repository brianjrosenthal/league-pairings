<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

class CsvImportHelper {
    
    // Validate uploaded CSV file
    public static function validateUploadedFile(array $uploadedFile): void {
        if (!isset($uploadedFile['error']) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }
        
        if (!isset($uploadedFile['size']) || $uploadedFile['size'] === 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }
        
        // 10MB max file size
        if ($uploadedFile['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('File size exceeds 10MB limit.');
        }
        
        // Check file extension
        $filename = $uploadedFile['name'] ?? '';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            throw new RuntimeException('Only CSV files are allowed.');
        }
    }
    
    // Save uploaded file to temporary location
    public static function saveUploadedFile(array $uploadedFile): string {
        self::validateUploadedFile($uploadedFile);
        
        // Create temp directory if it doesn't exist
        $tempDir = __DIR__ . '/../logs/csv_imports';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // Generate unique filename
        $uniqueId = uniqid('csv_', true);
        $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        $tempFile = $tempDir . '/' . $uniqueId . '.' . $ext;
        
        if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFile)) {
            throw new RuntimeException('Failed to save uploaded file.');
        }
        
        return $tempFile;
    }
    
    // Get CSV headers (first row)
    public static function getCSVHeaders(string $filePath, string $delimiter = ','): array {
        if (!file_exists($filePath)) {
            throw new RuntimeException('File not found.');
        }
        
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Failed to open file.');
        }
        
        $headers = fgetcsv($handle, 0, $delimiter);
        fclose($handle);
        
        if ($headers === false || empty($headers)) {
            throw new RuntimeException('Failed to read CSV headers.');
        }
        
        // Trim whitespace from headers
        return array_map('trim', $headers);
    }
    
    // Parse entire CSV file
    public static function parseCSV(string $filePath, string $delimiter = ','): array {
        if (!file_exists($filePath)) {
            throw new RuntimeException('File not found.');
        }
        
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Failed to open file.');
        }
        
        $rows = [];
        $headers = null;
        $lineNum = 0;
        
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNum++;
            
            if ($lineNum === 1) {
                // First row is headers
                $headers = array_map('trim', $data);
                continue;
            }
            
            if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
                // Skip empty rows
                continue;
            }
            
            // Combine headers with data to create associative array
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = isset($data[$index]) ? trim($data[$index]) : '';
            }
            $row['_line_number'] = $lineNum;
            
            $rows[] = $row;
        }
        
        fclose($handle);
        
        return $rows;
    }
    
    // Clean up temporary file
    public static function cleanupTempFile(string $filePath): void {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    // Get delimiter character from string
    public static function getDelimiterChar(string $delimiter): string {
        $delimiters = [
            'comma' => ',',
            'semicolon' => ';',
            'tab' => "\t",
            'pipe' => '|'
        ];
        
        return $delimiters[$delimiter] ?? ',';
    }
}
