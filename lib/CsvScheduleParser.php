<?php
declare(strict_types=1);

/**
 * CSV Schedule Parser
 * 
 * Parses uploaded CSV files and converts them to the internal schedule format
 * with support for flexible column mapping.
 */
class CsvScheduleParser {
    
    private array $rawData = [];
    private array $headers = [];
    
    /**
     * Parse CSV content and extract headers and data
     */
    public function parseCSV(string $csvContent): bool {
        $lines = str_getcsv($csvContent, "\n");
        
        if (empty($lines)) {
            return false;
        }
        
        // First line is headers
        $this->headers = str_getcsv($lines[0]);
        $this->rawData = [];
        
        // Parse data rows
        for ($i = 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') {
                continue; // Skip empty lines
            }
            
            $row = str_getcsv($lines[$i]);
            if (count($row) === count($this->headers)) {
                $this->rawData[] = array_combine($this->headers, $row);
            }
        }
        
        return !empty($this->rawData);
    }
    
    /**
     * Get detected column headers from CSV
     */
    public function getHeaders(): array {
        return $this->headers;
    }
    
    /**
     * Get number of data rows
     */
    public function getRowCount(): int {
        return count($this->rawData);
    }
    
    /**
     * Get preview of first few rows
     */
    public function getPreview(int $limit = 3): array {
        return array_slice($this->rawData, 0, $limit);
    }
    
    /**
     * Auto-detect column mappings based on header names and content
     */
    public function autoDetectMapping(): array {
        $mapping = [
            'date' => null,
            'day' => null,
            'time' => null,
            'division' => null,
            'location' => null,
            'team_a' => null,
            'team_b' => null
        ];
        
        foreach ($this->headers as $header) {
            $headerLower = strtolower(trim($header));
            
            // Date detection
            if (preg_match('/date/i', $headerLower) && $mapping['date'] === null) {
                $mapping['date'] = $header;
            }
            
            // Day detection
            if (preg_match('/day/i', $headerLower) && $mapping['day'] === null) {
                $mapping['day'] = $header;
            }
            
            // Time detection
            if (preg_match('/time|modifier/i', $headerLower) && $mapping['time'] === null) {
                $mapping['time'] = $header;
            }
            
            // Division detection
            if (preg_match('/division|league|group/i', $headerLower) && $mapping['division'] === null) {
                $mapping['division'] = $header;
            }
            
            // Location detection
            if (preg_match('/location|venue|field|court|site/i', $headerLower) && $mapping['location'] === null) {
                $mapping['location'] = $header;
            }
            
            // Team A detection (home team)
            if (preg_match('/team.*a|home|team.*1/i', $headerLower) && $mapping['team_a'] === null) {
                $mapping['team_a'] = $header;
            }
            
            // Team B detection (away team)
            if (preg_match('/team.*b|away|visitor|team.*2/i', $headerLower) && $mapping['team_b'] === null) {
                $mapping['team_b'] = $header;
            }
        }
        
        return $mapping;
    }
    
    /**
     * Convert CSV data to internal schedule format using provided mapping
     */
    public function convertToScheduleFormat(array $mapping): array {
        $schedule = [];
        
        foreach ($this->rawData as $row) {
            $game = [];
            
            // Extract date
            if (!empty($mapping['date']) && isset($row[$mapping['date']])) {
                $date = $this->parseDate($row[$mapping['date']]);
                if ($date) {
                    $game['date'] = $date;
                }
            }
            
            // Extract day (or calculate from date)
            if (!empty($mapping['day']) && isset($row[$mapping['day']])) {
                $game['day'] = trim($row[$mapping['day']]);
            } elseif (isset($game['date'])) {
                $game['day'] = date('l', strtotime($game['date']));
            }
            
            // Extract time
            if (!empty($mapping['time']) && isset($row[$mapping['time']])) {
                $game['time_modifier'] = trim($row[$mapping['time']]);
            }
            
            // Extract division
            if (!empty($mapping['division']) && isset($row[$mapping['division']])) {
                $game['division_name'] = trim($row[$mapping['division']]);
            }
            
            // Extract location
            if (!empty($mapping['location']) && isset($row[$mapping['location']])) {
                $game['location_name'] = trim($row[$mapping['location']]);
            }
            
            // Extract team A
            if (!empty($mapping['team_a']) && isset($row[$mapping['team_a']])) {
                $game['team_a_name'] = trim($row[$mapping['team_a']]);
            }
            
            // Extract team B
            if (!empty($mapping['team_b']) && isset($row[$mapping['team_b']])) {
                $game['team_b_name'] = trim($row[$mapping['team_b']]);
            }
            
            // Only include if we have minimum required fields
            if (isset($game['date']) && isset($game['division_name']) && 
                isset($game['team_a_name']) && isset($game['team_b_name'])) {
                $schedule[] = $game;
            }
        }
        
        return $schedule;
    }
    
    /**
     * Parse various date formats to YYYY-MM-DD
     */
    private function parseDate(string $dateStr): ?string {
        $dateStr = trim($dateStr);
        
        // Try various common date formats
        $formats = [
            'Y-m-d',      // 2025-12-02
            'm/d/Y',      // 12/02/2025
            'd/m/Y',      // 02/12/2025
            'Y/m/d',      // 2025/12/02
            'm-d-Y',      // 12-02-2025
            'd-m-Y',      // 02-12-2025
            'M j, Y',     // Dec 2, 2025
            'F j, Y',     // December 2, 2025
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateStr);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($dateStr);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return null;
    }
    
    /**
     * Validate that required fields are mapped
     */
    public function validateMapping(array $mapping): array {
        $errors = [];
        
        if (empty($mapping['date'])) {
            $errors[] = 'Date column must be mapped';
        }
        
        if (empty($mapping['division'])) {
            $errors[] = 'Division column must be mapped';
        }
        
        if (empty($mapping['location'])) {
            $errors[] = 'Location column must be mapped';
        }
        
        if (empty($mapping['team_a'])) {
            $errors[] = 'Team A column must be mapped';
        }
        
        if (empty($mapping['team_b'])) {
            $errors[] = 'Team B column must be mapped';
        }
        
        return $errors;
    }
    
    /**
     * Get statistics about the parsed schedule
     */
    public function getStatistics(array $schedule): array {
        $divisions = [];
        $teams = [];
        $dates = [];
        
        foreach ($schedule as $game) {
            if (isset($game['division_name'])) {
                $divisions[$game['division_name']] = true;
            }
            if (isset($game['team_a_name'])) {
                $teams[$game['team_a_name']] = true;
            }
            if (isset($game['team_b_name'])) {
                $teams[$game['team_b_name']] = true;
            }
            if (isset($game['date'])) {
                $dates[$game['date']] = true;
            }
        }
        
        return [
            'total_games' => count($schedule),
            'divisions' => count($divisions),
            'teams' => count($teams),
            'dates' => count($dates),
            'division_names' => array_keys($divisions),
            'team_names' => array_keys($teams)
        ];
    }
}
