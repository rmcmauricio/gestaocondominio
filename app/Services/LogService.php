<?php

namespace App\Services;

class LogService
{
    protected $logFile;
    protected $logPath;

    public function __construct()
    {
        $this->logPath = __DIR__ . '/../../logs/php_error.log';
        $this->logFile = $this->logPath;
    }

    /**
     * Get log file information
     */
    public function getLogFileInfo(): array
    {
        if (!file_exists($this->logFile)) {
            return [
                'exists' => false,
                'size' => 0,
                'size_formatted' => '0 B',
                'last_modified' => null,
                'line_count' => 0
            ];
        }

        $size = filesize($this->logFile);
        $lastModified = filemtime($this->logFile);

        return [
            'exists' => true,
            'size' => $size,
            'size_formatted' => $this->formatBytes($size),
            'last_modified' => date('Y-m-d H:i:s', $lastModified),
            'last_modified_timestamp' => $lastModified,
            'line_count' => $this->estimateLineCount()
        ];
    }

    /**
     * Remove leading whitespace from each line of a multi-line message
     */
    protected function trimMessageLines(string $message): string
    {
        // First, remove all types of whitespace from start and end of entire message
        $message = preg_replace('/^[\s\x{00A0}\x{2000}-\x{200B}]+|[\s\x{00A0}\x{2000}-\x{200B}]+$/u', '', $message);
        
        // Split into lines and remove leading whitespace from each line
        $lines = explode("\n", $message);
        $trimmedLines = [];
        foreach ($lines as $line) {
            // Remove all types of leading whitespace (spaces, tabs, non-breaking spaces, etc.)
            $trimmedLine = preg_replace('/^[\s\x{00A0}\x{2000}-\x{200B}]+/u', '', $line);
            // Also remove trailing whitespace from each line
            $trimmedLine = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}]+$/u', '', $trimmedLine);
            $trimmedLines[] = $trimmedLine;
        }
        
        // Join lines and ensure no leading/trailing whitespace
        $result = implode("\n", $trimmedLines);
        return preg_replace('/^[\s\x{00A0}\x{2000}-\x{200B}]+|[\s\x{00A0}\x{2000}-\x{200B}]+$/u', '', $result);
    }

    /**
     * Parse a log line into structured data
     */
    public function parseLogLine(string $line): array
    {
        $parsed = [
            'raw' => $line,
            'timestamp' => null,
            'date' => null,
            'time' => null,
            'level' => 'INFO',
            'message' => $this->trimMessageLines(trim($line)),
            'line_number' => null
        ];

        // Try to parse format: [DD-MMM-YYYY HH:MM:SS UTC] Message...
        // Format examples: [23-Jan-2026 12:06:45 UTC] or [17-Jan-2026 16:15:06 UTC]
        if (preg_match('/^\[(\d{2}-[A-Za-z]{3}-\d{4}) (\d{2}:\d{2}:\d{2}) UTC\]\s*(.+)$/s', $line, $matches)) {
            $dateStr = $matches[1]; // e.g., "23-Jan-2026"
            $timeStr = $matches[2]; // e.g., "12:06:45"
            $parsed['date'] = $dateStr;
            $parsed['time'] = $timeStr;
            // Convert date format from "DD-MMM-YYYY" to timestamp
            // strtotime can parse "DD-MMM-YYYY HH:MM:SS" format directly
            $parsed['timestamp'] = strtotime($dateStr . ' ' . $timeStr);
            // Extract message and remove ALL leading whitespace immediately (including Unicode spaces)
            $message = preg_replace('/^[\s\x{00A0}\x{2000}-\x{200B}]+/u', '', $matches[3]);
            $parsed['message'] = $this->trimMessageLines($message);
        }

        // Detect error level
        $messageUpper = strtoupper($parsed['message']);
        // Check for PHP errors first (PHP Fatal, PHP Warning, etc.)
        if (preg_match('/PHP\s+(FATAL|FATAL\s+ERROR)/', $messageUpper)) {
            $parsed['level'] = 'ERROR';
        } elseif (preg_match('/PHP\s+WARNING/', $messageUpper)) {
            $parsed['level'] = 'WARNING';
        } elseif (preg_match('/PHP\s+NOTICE/', $messageUpper)) {
            $parsed['level'] = 'NOTICE';
        } elseif (preg_match('/\b(ERROR|FATAL|CRITICAL)\b/', $messageUpper)) {
            $parsed['level'] = 'ERROR';
        } elseif (preg_match('/\b(WARNING|WARN)\b/', $messageUpper)) {
            $parsed['level'] = 'WARNING';
        } elseif (preg_match('/\b(NOTICE)\b/', $messageUpper)) {
            $parsed['level'] = 'NOTICE';
        } elseif (preg_match('/\b(DEBUG)\b/', $messageUpper)) {
            $parsed['level'] = 'DEBUG';
        } elseif (preg_match('/\b(INFO)\b/', $messageUpper)) {
            $parsed['level'] = 'INFO';
        }

        return $parsed;
    }

    /**
     * Read log lines with pagination and filters
     */
    public function readLogLines(int $offset = 0, int $limit = 50, array $filters = []): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $search = $filters['search'] ?? '';
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $level = $filters['level'] ?? '';
        $direction = $filters['direction'] ?? 'desc'; // 'desc' = newest first, 'asc' = oldest first

        $lines = [];
        $file = new \SplFileObject($this->logFile, 'r');

        // If searching, use grep for efficiency (if available)
        if (!empty($search) && function_exists('shell_exec') && !empty(shell_exec('which grep'))) {
            return $this->searchWithGrep($search, $offset, $limit, $filters);
        }

        // Read from end if direction is 'desc' (newest first)
        if ($direction === 'desc') {
            return $this->readFromEnd($file, $offset, $limit, $filters);
        }

        // Read from beginning (oldest first)
        return $this->readFromBeginning($file, $offset, $limit, $filters);
    }

    /**
     * Read lines from the end of file (newest first)
     */
    protected function readFromEnd(\SplFileObject $file, int $offset, int $limit, array $filters): array
    {
        $lines = [];
        
        // For very large files, use tail command if available (much faster)
        if (function_exists('shell_exec') && !empty(shell_exec('which tail'))) {
            $fileSize = filesize($this->logFile);
            if ($fileSize > 5 * 1024 * 1024) { // > 5MB
                return $this->readFromEndWithTail($offset, $limit, $filters);
            }
        }
        
        // Get total lines by seeking to end
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        
        if ($totalLines === 0) {
            return [];
        }
        
        // Read backwards: start from end and work backwards
        // We need to collect matching lines, then apply offset and limit
        $allMatchingLines = [];
        $currentLineNum = $totalLines - 1;
        
        // Read backwards up to a reasonable limit (to avoid memory issues)
        $maxReadLines = min(10000, $totalLines); // Read max 10k lines backwards
        
        $file->seek(max(0, $totalLines - $maxReadLines));
        
        while (!$file->eof() && $file->key() < $totalLines) {
            $line = $file->current();
            $lineNum = $file->key();
            $file->next();
            
            if ($line === false || trim($line) === '') {
                continue;
            }
            
            // Apply filters
            if ($this->matchesFilters($line, $filters)) {
                $parsed = $this->parseLogLine($line);
                $parsed['line_number'] = $lineNum + 1; // 1-indexed
                $allMatchingLines[] = $parsed;
            }
        }
        
        // Reverse to get newest first, then apply offset and limit
        $allMatchingLines = array_reverse($allMatchingLines);
        $lines = array_slice($allMatchingLines, $offset, $limit);
        
        return $lines;
    }

    /**
     * Read from end using tail command (much faster for large files)
     */
    protected function readFromEndWithTail(int $offset, int $limit, array $filters): array
    {
        $escapedFile = escapeshellarg($this->logFile);
        $numLines = ($offset + $limit) * 2; // Read more to account for filtering
        
        // Use tail to get last N lines
        $command = "tail -n {$numLines} {$escapedFile}";
        $output = shell_exec($command);
        
        if (empty($output)) {
            return [];
        }
        
        $allLines = explode("\n", trim($output));
        $allMatchingLines = [];
        
        foreach ($allLines as $line) {
            if (trim($line) === '') {
                continue;
            }
            
            if ($this->matchesFilters($line, $filters)) {
                $parsed = $this->parseLogLine($line);
                $allMatchingLines[] = $parsed;
            }
        }
        
        // Reverse to get newest first, then apply offset and limit
        $allMatchingLines = array_reverse($allMatchingLines);
        $lines = array_slice($allMatchingLines, $offset, $limit);
        
        return $lines;
    }

    /**
     * Read lines from the beginning of file (oldest first)
     */
    protected function readFromBeginning(\SplFileObject $file, int $offset, int $limit, array $filters): array
    {
        $lines = [];
        $file->rewind();
        
        $matched = 0;
        $skipped = 0;
        
        while (!$file->eof()) {
            $line = $file->current();
            $file->next();
            
            if ($line === false || trim($line) === '') {
                continue;
            }
            
            // Apply filters
            if ($this->matchesFilters($line, $filters)) {
                if ($skipped >= $offset) {
                    $parsed = $this->parseLogLine($line);
                    $parsed['line_number'] = $file->key();
                    $lines[] = $parsed;
                    $matched++;
                    
                    if ($matched >= $limit) {
                        break;
                    }
                } else {
                    $skipped++;
                }
            }
        }
        
        return $lines;
    }

    /**
     * Search using grep (much faster for large files)
     */
    protected function searchWithGrep(string $search, int $offset, int $limit, array $filters): array
    {
        $escapedSearch = escapeshellarg($search);
        $escapedFile = escapeshellarg($this->logFile);
        
        // Use grep to find matching lines with line numbers
        $command = "grep -n {$escapedSearch} {$escapedFile}";
        $output = shell_exec($command);
        
        if (empty($output)) {
            return [];
        }
        
        $matchingLines = explode("\n", trim($output));
        $lines = [];
        
        // Apply additional filters and pagination
        $matched = 0;
        $skipped = 0;
        
        foreach ($matchingLines as $lineInfo) {
            if (preg_match('/^(\d+):(.+)$/', $lineInfo, $matches)) {
                $lineNumber = (int)$matches[1];
                $line = $matches[2];
                
                // Apply other filters
                $tempFilters = $filters;
                $tempFilters['search'] = ''; // Already filtered by grep
                
                if ($this->matchesFilters($line, $tempFilters)) {
                    if ($skipped >= $offset) {
                        $parsed = $this->parseLogLine($line);
                        $parsed['line_number'] = $lineNumber;
                        $lines[] = $parsed;
                        $matched++;
                        
                        if ($matched >= $limit) {
                            break;
                        }
                    } else {
                        $skipped++;
                    }
                }
            }
        }
        
        return $lines;
    }

    /**
     * Check if a line matches the filters
     */
    protected function matchesFilters(string $line, array $filters): bool
    {
        // Search text filter
        if (!empty($filters['search'])) {
            if (stripos($line, $filters['search']) === false) {
                return false;
            }
        }
        
        // Parse line to check date and level
        $parsed = $this->parseLogLine($line);
        
        // Date filter
        if (!empty($filters['date_from']) && $parsed['timestamp']) {
            $dateFrom = strtotime($filters['date_from'] . ' 00:00:00');
            if ($parsed['timestamp'] < $dateFrom) {
                return false;
            }
        }
        
        if (!empty($filters['date_to']) && $parsed['timestamp']) {
            $dateTo = strtotime($filters['date_to'] . ' 23:59:59');
            if ($parsed['timestamp'] > $dateTo) {
                return false;
            }
        }
        
        // Level filter
        if (!empty($filters['level'])) {
            if (strtoupper($parsed['level']) !== strtoupper($filters['level'])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Count total lines matching filters
     */
    public function countLogLines(array $filters = []): int
    {
        if (!file_exists($this->logFile)) {
            return 0;
        }

        // If searching with grep, count grep results (much faster)
        if (!empty($filters['search']) && function_exists('shell_exec') && !empty(shell_exec('which grep'))) {
            $escapedSearch = escapeshellarg($filters['search']);
            $escapedFile = escapeshellarg($this->logFile);
            $command = "grep -c {$escapedSearch} {$escapedFile} 2>/dev/null";
            $count = shell_exec($command);
            $count = (int)trim($count);
            
            // If we have other filters, we need to filter the grep results
            if ($count > 0 && (!empty($filters['date_from']) || !empty($filters['date_to']) || !empty($filters['level']))) {
                // Re-count with all filters applied
                return $this->countWithAllFilters($filters);
            }
            
            return $count;
        }

        // For very large files without search, limit counting to avoid timeout
        $fileSize = filesize($this->logFile);
        if ($fileSize > 10 * 1024 * 1024 && empty($filters['search'])) { // > 10MB
            // Estimate based on sampling
            return $this->estimateFilteredCount($filters);
        }

        // Otherwise, count line by line (with reasonable limit)
        $file = new \SplFileObject($this->logFile, 'r');
        $count = 0;
        $maxLinesToCheck = 50000; // Limit to avoid timeout
        $linesChecked = 0;
        
        while (!$file->eof() && $linesChecked < $maxLinesToCheck) {
            $line = $file->current();
            $file->next();
            $linesChecked++;
            
            if ($line === false || trim($line) === '') {
                continue;
            }
            
            if ($this->matchesFilters($line, $filters)) {
                $count++;
            }
        }
        
        // If we hit the limit, estimate total
        if ($linesChecked >= $maxLinesToCheck && $file->eof() === false) {
            $totalLines = $this->estimateLineCount();
            $ratio = $count / $linesChecked;
            return (int)($totalLines * $ratio);
        }
        
        return $count;
    }

    /**
     * Count with all filters applied (when grep was used for search)
     */
    protected function countWithAllFilters(array $filters): int
    {
        $file = new \SplFileObject($this->logFile, 'r');
        $count = 0;
        $maxLinesToCheck = 10000; // Limit for performance
        
        while (!$file->eof() && $count < $maxLinesToCheck) {
            $line = $file->current();
            $file->next();
            
            if ($line === false || trim($line) === '') {
                continue;
            }
            
            if ($this->matchesFilters($line, $filters)) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Estimate filtered count by sampling
     */
    protected function estimateFilteredCount(array $filters): int
    {
        $file = new \SplFileObject($this->logFile, 'r');
        $sampleSize = 1000;
        $matches = 0;
        $totalLines = $this->estimateLineCount();
        
        // Sample random lines
        $file->seek(0);
        $checked = 0;
        
        while (!$file->eof() && $checked < $sampleSize) {
            $line = $file->current();
            $file->next();
            $checked++;
            
            if ($line === false || trim($line) === '') {
                continue;
            }
            
            if ($this->matchesFilters($line, $filters)) {
                $matches++;
            }
        }
        
        if ($checked === 0) {
            return 0;
        }
        
        $ratio = $matches / $checked;
        return (int)($totalLines * $ratio);
    }

    /**
     * Estimate line count (for large files, don't count all)
     */
    protected function estimateLineCount(): int
    {
        if (!file_exists($this->logFile)) {
            return 0;
        }

        $size = filesize($this->logFile);
        
        // For very large files, estimate based on average line length
        if ($size > 10 * 1024 * 1024) { // > 10MB
            $file = new \SplFileObject($this->logFile, 'r');
            $sampleSize = min(1000, $size);
            $file->seek(0);
            
            $sampleLines = 0;
            $bytesRead = 0;
            
            while (!$file->eof() && $bytesRead < $sampleSize) {
                $line = $file->current();
                $bytesRead += strlen($line);
                $sampleLines++;
                $file->next();
            }
            
            if ($sampleLines > 0) {
                $avgLineLength = $bytesRead / $sampleLines;
                return (int)($size / $avgLineLength);
            }
        }
        
        // For smaller files, count exactly
        return $this->countLogLines([]);
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Clear log file (truncate to empty)
     */
    public function clearLog(): bool
    {
        if (!file_exists($this->logFile)) {
            return true;
        }
        
        return file_put_contents($this->logFile, '') !== false;
    }
}
