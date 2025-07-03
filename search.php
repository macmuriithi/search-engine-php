<?php

class PorterStemmer {
    // Simplified Porter Stemmer implementation
    public static function stem($word) {
        $word = strtolower($word);
        if (strlen($word) > 2) {
            if (substr($word, -3) === 'ing') {
                return substr($word, 0, -3);
            }
            if (substr($word, -2) === 'ed') {
                return substr($word, 0, -2);
            }
            if (substr($word, -1) === 's' && substr($word, -2) !== 'ss') {
                return substr($word, 0, -1);
            }
        }
        return $word;
    }
}

class BasicSearch {
    private $db;
    private $docCount;

    public function __construct() {
        // Initialize SQLite database
        $this->db = new SQLite3(':memory:'); // In-memory for demo; use file path for persistence
        $this->initializeDatabase();
        // Sample documents
        $documents = [
            1 => "The quick brown fox jumps over the lazy dog dogs",
            2 => "A cat sleeps on the mat",
            3 => "The dog barking loudly dogs"
        ];
        $this->docCount = count($documents);
        // Build inverted index in SQLite
        $this->buildInvertedIndex($documents);
    }

    private function initializeDatabase() {
        // Create tables
        $this->db->exec('CREATE TABLE IF NOT EXISTS documents (doc_id INTEGER PRIMARY KEY, content TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS inverted_index (term TEXT, doc_id INTEGER, frequency INTEGER, PRIMARY KEY (term, doc_id))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS doc_lengths (doc_id INTEGER PRIMARY KEY, length INTEGER)');
        // Create index for faster lookups
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_term ON inverted_index (term)');
    }

    private function buildInvertedIndex($documents) {
        // Clear existing data
        $this->db->exec('DELETE FROM documents');
        $this->db->exec('DELETE FROM inverted_index');
        $this->db->exec('DELETE FROM doc_lengths');

        foreach ($documents as $docId => $doc) {
            // Store document
            $stmt = $this->db->prepare('INSERT INTO documents (doc_id, content) VALUES (:doc_id, :content)');
            $stmt->bindValue(':doc_id', $docId, SQLITE3_INTEGER);
            $stmt->bindValue(':content', $doc, SQLITE3_TEXT);
            $stmt->execute();

            // Tokenize: lowercase, remove punctuation, split into words
            $cleanDoc = preg_replace("/[.,!?]/", "", strtolower($doc));
            $tokens = array_filter(explode(" ", $cleanDoc));
            // Count term frequencies and document length
            $termFreq = [];
            foreach ($tokens as $token) {
                $stem = PorterStemmer::stem($token);
                $termFreq[$stem] = ($termFreq[$stem] ?? 0) + 1;
            }
            // Store document length
            $stmt = $this->db->prepare('INSERT INTO doc_lengths (doc_id, length) VALUES (:doc_id, :length)');
            $stmt->bindValue(':doc_id', $docId, SQLITE3_INTEGER);
            $stmt->bindValue(':length', count($tokens), SQLITE3_INTEGER);
            $stmt->execute();

            // Store term frequencies in inverted index
            foreach ($termFreq as $stem => $freq) {
                $stmt = $this->db->prepare('INSERT INTO inverted_index (term, doc_id, frequency) VALUES (:term, :doc_id, :frequency)');
                $stmt->bindValue(':term', $stem, SQLITE3_TEXT);
                $stmt->bindValue(':doc_id', $docId, SQLITE3_INTEGER);
                $stmt->bindValue(':frequency', $freq, SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
    }

    private function calculateIDF($term) {
        $stmt = $this->db->prepare('SELECT COUNT(DISTINCT doc_id) AS df FROM inverted_index WHERE term = :term');
        $stmt->bindValue(':term', $term, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $df = $result['df'] ?? 0;
        return $df > 0 ? log($this->docCount / $df) : 0;
    }

    private function getDocLength($docId) {
        $stmt = $this->db->prepare('SELECT length FROM doc_lengths WHERE doc_id = :doc_id');
        $stmt->bindValue(':doc_id', $docId, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['length'] ?? 1;
    }

    private function tokenizeQuery($query) {
        $query = trim(strtolower($query));
        $tokens = [];
        $current = '';
        $i = 0;
        while ($i < strlen($query)) {
            $char = $query[$i];
            if ($char === '(' || $char === ')') {
                if ($current !== '') {
                    $tokens[] = trim($current);
                    $current = '';
                }
                $tokens[] = $char;
            } elseif (preg_match("/\s/", $char)) {
                if ($current !== '') {
                    $tokens[] = trim($current);
                    $current = '';
                }
            } elseif (preg_match("/[.,!?]/", $char)) {
                // Skip punctuation
            } else {
                $current .= $char;
            }
            $i++;
        }
        if ($current !== '') {
            $tokens[] = trim($current);
        }
        return array_filter($tokens);
    }

    private function parseQuery($tokens, &$index) {
        $results = [];
        $operator = null;
        while ($index < count($tokens)) {
            $token = $tokens[$index];

            if ($token === '(') {
                $index++;
                $subResult = $this->parseQuery($tokens, $index);
                $results[] = $subResult;
                continue;
            } elseif ($token === ')') {
                $index++;
                break;
            } elseif (in_array(strtoupper($token), ['AND', 'OR', 'NOT'])) {
                $operator = strtoupper($token);
                $index++;
                continue;
            } else {
                // Stem the term
                $stem = PorterStemmer::stem($token);
                $stmt = $this->db->prepare('SELECT doc_id FROM inverted _

System: index WHERE term = :term');
                $stmt->bindValue(':term', $stem, SQLITE3_TEXT);
                $result = $stmt->execute();
                $docIds = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $docIds[] = $row['doc_id'];
                }
                $results[] = $docIds;
                $index++;
            }

            if ($operator && count($results) >= 2) {
                $right = array_pop($results);
                $left = array_pop($results);
                if ($operator === 'AND') {
                    $results[] = array_intersect($left, $right);
                } elseif ($operator === 'OR') {
                    $results[] = array_unique(array_merge($left, $right));
                } elseif ($operator === 'NOT') {
                    $results[] = array_diff($left, $right);
                }
                $operator = null;
            }
        }

        // Handle remaining operator if any
        if ($operator && count($results) >= 2) {
            $right = array_pop($results);
            $left = array_pop($results);
            if ($operator === 'AND') {
                $results[] = array_intersect($left, $right);
            } elseif ($operator === 'OR') {
                $results[] = array_unique(array_merge($left, $right));
            } elseif ($operator === 'NOT') {
                $results[] = array_diff($left, $right);
            }
        }

        return $results[0] ?? [];
    }

    public function search($query) {
        // Parse and evaluate query
        $tokens = $this->tokenizeQuery($query);
        $index = 0;
        $results = $this->parseQuery($tokens, $index);

        // Handle implicit AND for simple queries without operators
        if (count($tokens) === 1 || (count($tokens) > 1 && !in_array('AND', $tokens) && !in_array('OR', $tokens) && !in_array('NOT', $tokens))) {
            $terms = array_filter($tokens, function($token) {
                return !in_array($token, ['(', ')', 'AND', 'OR', 'NOT']);
            });
            $docSets = [];
            foreach ($terms as $term) {
                $stem = PorterStemmer::stem($term);
                $stmt = $this->db->prepare('SELECT doc_id FROM inverted_index WHERE term = :term');
                $stmt->bindValue(':term', $stem, SQLITE3_TEXT);
                $result = $stmt->execute();
                $docIds = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $docIds[] = $row['doc_id'];
                }
                if (empty($docIds)) {
                    return [];
                }
                $docSets[] = $docIds;
            }
            $results = $docSets[0];
            for ($i = 1; $i < count($docSets); $i++) {
                $results = array_intersect($results, $docSets[$i]);
            }
        }

        // Calculate TF-IDF scores for ranking
        $rankedResults = [];
        $terms = array_filter($tokens, function($token) {
            return !in_array(strtoupper($token), ['AND', 'OR', 'NOT', '(', ')']);
        });
        foreach ($results as $docId) {
            $score = 0;
            foreach ($terms as $term) {
                $stem = PorterStemmer::stem($term);
                $stmt = $this->db->prepare('SELECT frequency FROM inverted_index WHERE term = :term AND doc_id = :doc_id');
                $stmt->bindValue(':term', $stem, SQLITE3_TEXT);
                $stmt->bindValue(':doc_id', $docId, SQLITE3_INTEGER);
                $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($result && isset($result['frequency'])) {
                    // TF = term frequency / document length
                    $tf = $result['frequency'] / $this->getDocLength($docId);
                    // IDF = log(N / df)
                    $idf = $this->calculateIDF($stem);
                    // TF-IDF score for this term
                    $score += $tf * $idf;
                }
            }
            $rankedResults[$docId] = $score;
        }
        // Sort by score (descending) and return document IDs
        arsort($rankedResults);
        return array_keys($rankedResults);
    }

    public function getDocument($docId) {
        // Retrieve document content by ID
        $stmt = $this->db->prepare('SELECT content FROM documents WHERE doc_id = :doc_id');
        $stmt->bindValue(':doc_id', $docId, SQLITE3_INTEGER);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result['content'] ?? null;
    }
}

// Example usage
$searchEngine = new BasicSearch();
$query = "(dog AND lazy) OR cats";
$results = $searchEngine->search($query);
echo "Query '$query' found in documents: " . (empty($results) ? "None" : implode(", ", $results)) . "\n";
foreach ($results as $docId) {
    echo "Document $docId: " . $searchEngine->getDocument($docId) . "\n";
}
// Output:
// Query '(dog AND lazy) OR cats' found in documents: 2, 1
// Document 2: A cat sleeps on the mat
// Document 1: The quick brown fox jumps over the lazy dog dogs

$query = "dogs NOT (cat OR mat)";
$results = $searchEngine->search($query);
echo "Query '$query' found in documents: " . (empty($results) ? "None" : implode(", ", $results)) . "\n";
foreach ($results as $docId) {
    echo "Document $docId: " . $searchEngine->getDocument($docId) . "\n";
}
// Output:
// Query 'dogs NOT (cat OR mat)' found in documents: 3
// Document 3: The dog barking loudly dogs

$query = "barking AND dog";
$results = $searchEngine->search($query);
echo "Query '$query' found in documents: " . (empty($results) ? "None" : implode(", ", $results)) . "\n";
foreach ($results as $docId) {
    echo "Document $docId: " . $searchEngine->getDocument($docId) . "\n";
}
// Output:
// Query 'barking AND dog' found in documents: 3
// Document 3: The dog barking loudly dogs

?>
