# PHP Search Engine

This repository, **PHP-Search-Engine**, contains a 12-stage implementation of a search engine built in PHP, starting from a basic binary presence lookup and progressing to a scalable inverted index with SQLite storage. Each stage introduces new features and optimizations, making this an educational resource for understanding search engine development.

## Overview
The search engine evolves through 12 stages, each building on the previous one to add functionality and improve performance. The final implementation supports:
- Persistent storage with SQLite.
- Complex boolean queries (AND, OR, NOT, parentheses).
- Stemming for word variations.
- TF-IDF ranking for relevance.
- Efficient indexing for scalability.

Below is a detailed breakdown of each stage, including optimizations and code snippets.

## Stage 1: Basic Binary Presence Lookup
**Objective**: Check if a term exists in a collection of documents using a linear scan.

**Optimizations**:
- Case-insensitive search using `stripos`.
- Simple in-memory array for document storage.

**Snippet**:
```php
public function search($query) {
    foreach ($this->documents as $doc) {
        if (stripos($doc, strtolower($query)) !== false) {
            return true;
        }
    }
    return false;
}
```

## Stage 2: Document IDs and List of Matching Documents
**Objective**: Assign unique IDs to documents and return IDs of documents containing the query term.

**Optimizations**:
- Introduced document IDs for tracking.
- Returned array of matching document IDs instead of a booleanಸ

System: boolean.

**Snippet**:
```php
public function search($query) {
    $results = [];
    foreach ($this->documents as $docId => $doc) {
        if (stripos($doc, strtolower($query)) !== false) {
            $results[] = $docId;
        }
    }
    return $results;
}
```

## Stage 3: Basic Tokenization and Exact Word Matching
**Objective**: Tokenize documents into words and enable exact word matching.

**Optimizations**:
- Tokenized documents into words, removing punctuation and duplicates.
- Improved precision by matching whole words instead of substrings.

**Snippet**:
```php
private function tokenizeDocuments() {
    $tokenized = [];
    foreach ($this->documents as $docId => $doc) {
        $cleanDoc = preg_replace("/[.,!?]/", "", strtolower($doc));
        $tokens = array_filter(explode(" ", $cleanDoc));
        $tokenized[$docId] = array_unique($tokens);
    }
    return $tokenized;
}
```

## Stage 4: Simple Inverted Index
**Objective**: Introduce an inverted index for faster lookups.

**Optimizations**:
- Built an inverted index mapping words to document IDs.
- Reduced search time from O(n) to O(1) per term lookup.

**Snippet**:
```php
private function buildInvertedIndex() {
    $index = [];
    foreach ($this->documents as $docId => $doc) {
        $cleanDoc = preg_replace("/[.,!?]/", "", strtolower($doc));
        $tokens = array_unique(array_filter(explode(" ", $cleanDoc)));
        foreach ($tokens as $token) {
            if (!isset($index[$token])) {
                $index[$token] = [];
            }
            $index[$token][] = $docId;
        }
    }
    return $index;
}
```

## Stage 5: Multi-Word Queries with AND Logic
**Objective**: Support multi-word queries requiring all terms (AND logic).

**Optimizations**:
- Tokenized queries and computed intersection of document ID sets.
- Added document content retrieval for result display.

**Snippet**:
```php
public function search($query) {
    $terms = array_filter(explode(" ", preg_replace("/[.,!?]/", "", strtolower($query))));
    $docSets = [];
    foreach ($terms as $term) {
        if (isset($this->invertedIndex[$term])) {
            $docSets[] = $this->invertedIndex[$term];
        } else {
            return [];
        }
    }
    $results = $docSets[0];
    for ($i = 1; $i < count($docSets); $i++) {
        $results = array_intersect($results, $docSets[$i]);
    }
    return array_values($results);
}
```

## Stage 6: Boolean Logic (AND/OR)
**Objective**: Add support for OR operator in boolean queries.

**Optimizations**:
- Parsed queries with AND/OR operators using `preg_split`.
- Supported OR (union) alongside AND (intersection) for flexible queries.

**Snippet**:
```php
$parts = preg_split("/\s+(AND|OR)\s+/i", $query, -1, PREG_SPLIT_DELIM_CAPTURE);
if ($operator === "AND") {
    $results = array_intersect($docSet1, $docSet2);
} elseif ($operator === "OR") {
    $results = array_unique(array_merge($docSet1, $docSet2));
}
```

## Stage 7: Term Frequency Tracking and Ranking
**Objective**: Track term frequencies and rank results by frequency.

**Optimizations**:
- Stored term frequencies in the inverted index.
- Ranked documents by sum of term frequencies for query terms.

**Snippet**:
```php
foreach ($results as $docId) {
    $score = 0;
    $terms = ...; // Query terms
    foreach ($terms as $term) {
        if (isset($this->invertedIndex[$term][$docId])) {
            $score += $this->invertedIndex[$term][$docId];
        }
    }
    $rankedResults[$docId] = $score;
}
arsort($rankedResults);
```

## Stage 8: TF-IDF Scoring
**Objective**: Implement TF-IDF (Term Frequency-Inverse Document Frequency) scoring for more accurate ranking.

**Description**:
TF-IDF is a core ranking mechanism that balances term frequency (how often a term appears in a document) with its rarity across the document collection, ensuring more relevant results. Term Frequency (TF) measures the importance of a term within a specific document, calculated as the number of times the term appears divided by the document's total word count (normalization). Inverse Document Frequency (IDF) reduces the weight of terms that appear in many documents, calculated as the logarithm of the total number of documents divided by the number of documents containing the term. The TF-IDF score for a term is the product of TF and IDF, and the total score for a document is the sum of TF-IDF scores for all query terms. This approach prioritizes documents where query terms are both frequent and rare across the collection, improving relevance over simple frequency-based ranking.

**Optimizations**:
- Added document length tracking to normalize TF (`term frequency / document length`).
- Implemented IDF calculation using `log(N/df)`, where `N` is the total number of documents and `df` is the number of documents containing the term.
- Ranked results by the sum of TF-IDF scores, ensuring documents with higher relevance (frequent and rare terms) rank higher.
- Improved result quality by penalizing common terms (e.g., "the") that appear in most documents.

**Snippet**:
```php
private function calculateIDF($term) {
    $df = isset($this->invertedIndex[$term]) ? count($this->invertedIndex[$term]) : 0;
    return $df > 0 ? log($this->docCount / $df) : 0;
}

public function search($query) {
    // ... (query processing)
    $rankedResults = [];
    foreach ($results as $docId) {
        $score = 0;
        foreach ($terms as $term) {
            if (isset($this->invertedIndex[$term][$docId])) {
                $tf = $this->invertedIndex[$term][$docId] / ($this->docLengths[$docId] ?: 1);
                $idf = $this->calculateIDF($term);
                $score += $tf * $idf;
            }
        }
        $rankedResults[$docId] = $score;
    }
    arsort($rankedResults);
    return array_keys($rankedResults);
}
```

## Stage 9: NOT Operator
**Objective**: Add support for NOT operator to exclude terms.

**Optimizations**:
- Used `array_diff` to exclude documents containing specified terms.
- Maintained TF-IDF scoring for included terms.

**Snippet**:
```php
if ($operator === "NOT") {
    $results = array_diff($docSet1, $docSet2);
}
```

## Stage 10: Complex Boolean Queries with Parentheses
**Objective**: Support nested boolean expressions with parentheses.

**Optimizations**:
- Implemented a recursive descent parser for complex queries.
- Handled nested AND, OR, and NOT operators efficiently.

**Snippet**:
```php
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
        } // ... Handle operators and terms
    }
    // ... Combine results
}
```

## Stage 11: Stemming for Word Variations
**Objective**: Add stemming to match word variations (e.g., "dogs" → "dog").

**Optimizations**:
- Implemented a simplified Porter Stemmer.
- Stored stemmed terms in the inverted index, improving recall.

**Snippet**:
```php
class PorterStemmer {
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
```

## Stage 12: Persistent Storage with SQLite
**Objective**: Use SQLite for scalable document and index storage.

**Optimizations**:
- Stored documents, inverted index, and document lengths in SQLite tables.
- Added an index on the `term` column for faster lookups.
- Maintained all previous features (stemming, boolean queries, TF-IDF).

**Snippet**:
```php
private function initializeDatabase() {
    $this->db->exec('CREATE TABLE documents (doc_id INTEGER PRIMARY KEY, content TEXT)');
    $this->db->exec('CREATE TABLE inverted_index (term TEXT, doc_id INTEGER, frequency INTEGER, PRIMARY KEY (term, doc_id))');
    $this->db->exec('CREATE TABLE doc_lengths (doc_id INTEGER PRIMARY KEY, length INTEGER)');
    $this->db->exec('CREATE INDEX idx_term ON inverted_index (term)');
}
```

## Installation
1. Ensure PHP and the SQLite3 extension are installed.
2. Clone the repository:
   ```bash
   git clone https://github.com/<your-username>/PHP-Search-Engine.git
   ```
3. Place the PHP file (e.g., `search_engine.php`) in your web server directory.
4. For persistent storage, modify the SQLite connection to use a file (e.g., `new SQLite3('database.sqlite')`).
5. Run the script or integrate it into your application.

## Usage
```php
$searchEngine = new BasicSearch();
$query = "(dog AND lazy) OR cats";
$results = $searchEngine->search($query);
echo "Query '$query' found in documents: " . (empty($results) ? "None" : implode(", ", $results)) . "\n";
foreach ($results as $docId) {
    echo "Document $docId: " . $searchEngine->getDocument($docId) . "\n";
}
```

## Future Improvements
- Add support for phrase queries (e.g., "quick fox").
- Implement a more robust stemming library (e.g., Snowball).
- Introduce synonyms or fuzzy matching.
- Optimize SQLite queries for larger datasets.

## License
This project is licensed under the MIT License. Feel free to use, modify, and distribute it for educational purposes.
