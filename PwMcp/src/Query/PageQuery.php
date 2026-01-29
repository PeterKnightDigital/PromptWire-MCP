<?php
/**
 * PW-MCP Page Query Handler
 * 
 * Provides methods for querying and formatting ProcessWire pages
 * for JSON output. Handles various field types and formats them
 * appropriately.
 * 
 * @package     PwMcp
 * @subpackage  Query
 * @author      Peter Knight
 * @license     MIT
 */

namespace PwMcp\Query;

/**
 * Handles page queries and formatting
 * 
 * This class provides methods to:
 * - Get a single page by ID or path
 * - Query multiple pages using ProcessWire selectors
 * - Format page data for JSON output
 * - Handle complex field types (files, images, repeaters, page references)
 * 
 * Field values are converted to JSON-serializable formats:
 * - Page references → {id, title, path}
 * - Files/images → {filename, url, size, dimensions} or just count
 * - Repeaters → Array of field value objects
 * - DateTime → ISO 8601 string
 */
class PageQuery {
    
    /**
     * ProcessWire instance
     * 
     * @var \ProcessWire\ProcessWire
     */
    private $wire;
    
    /**
     * Create a new PageQuery
     * 
     * @param \ProcessWire\ProcessWire $wire ProcessWire instance
     */
    public function __construct($wire) {
        $this->wire = $wire;
    }
    
    /**
     * Get a page by ID or path
     * 
     * Retrieves a single page and formats it for output. By default,
     * file/image fields only return counts to keep payloads small.
     * 
     * @param int|string $idOrPath    Page ID (numeric) or path (e.g., "/about/")
     * @param bool       $includeFiles Include full file/image metadata
     * @return array|null Page data or null if not found
     */
    public function getPage($idOrPath, bool $includeFiles = false): ?array {
        // Determine if input is an ID or path and fetch page
        if (is_numeric($idOrPath)) {
            $page = $this->wire->pages->get((int) $idOrPath);
        } else {
            $page = $this->wire->pages->get($idOrPath);
        }
        
        // Return null if page doesn't exist
        if (!$page || !$page->id) {
            return null;
        }
        
        return $this->formatPage($page, $includeFiles, true);
    }
    
    /**
     * Query pages using a ProcessWire selector
     * 
     * Finds multiple pages matching the given selector. Automatically
     * adds include=all to find unpublished/hidden pages, and applies
     * a default limit.
     * 
     * @param string $selector ProcessWire selector string
     * @param int    $limit    Maximum pages to return (default 100)
     * @return array Array of formatted page data
     */
    public function query(string $selector, int $limit = 100): array {
        // Add include=all if not specified to find all pages
        if (strpos($selector, 'include=') === false) {
            $selector .= ', include=all';
        }
        
        // Add limit if not specified to prevent huge result sets
        if (strpos($selector, 'limit=') === false) {
            $selector .= ", limit=$limit";
        }
        
        $pages = $this->wire->pages->find($selector);
        $results = [];
        
        // Format each page (without field values for performance)
        foreach ($pages as $page) {
            $results[] = $this->formatPage($page, false, false);
        }
        
        return $results;
    }
    
    /**
     * Format a page for JSON output
     * 
     * Converts a ProcessWire Page object to an associative array
     * suitable for JSON encoding.
     * 
     * @param \ProcessWire\Page $page          Page to format
     * @param bool              $includeFiles  Include file/image details
     * @param bool              $includeFields Include all field values
     * @return array Formatted page data
     */
    public function formatPage($page, bool $includeFiles = false, bool $includeFields = true): array {
        // Basic page properties (always included)
        $data = [
            'id' => $page->id,
            'name' => $page->name,
            'path' => $page->path,
            'url' => $page->url,
            'template' => $page->template->name,
            'title' => $page->title,
            'status' => $page->status,
        ];
        
        // Extended properties (when getting a single page)
        if ($includeFields) {
            $data['created'] = date('c', $page->created);     // ISO 8601 format
            $data['modified'] = date('c', $page->modified);
            $data['createdUser'] = $page->createdUser ? $page->createdUser->name : null;
            $data['modifiedUser'] = $page->modifiedUser ? $page->modifiedUser->name : null;
            $data['fields'] = $this->formatFields($page, $includeFiles);
        }
        
        return $data;
    }
    
    /**
     * Format all fields of a page
     * 
     * Iterates through all fields in the page's template and formats
     * each value appropriately.
     * 
     * @param \ProcessWire\Page $page         Page to get fields from
     * @param bool              $includeFiles Include file/image details
     * @return array Associative array of field name => formatted value
     */
    private function formatFields($page, bool $includeFiles = false): array {
        $fields = [];
        
        foreach ($page->template->fields as $field) {
            $value = $page->get($field->name);
            $fields[$field->name] = $this->formatFieldValue($field, $value, $includeFiles);
        }
        
        return $fields;
    }
    
    /**
     * Format a single field value for JSON output
     * 
     * Handles various ProcessWire field value types and converts
     * them to JSON-serializable formats.
     * 
     * Supported types:
     * - null/empty → null
     * - Page → {id, title, path}
     * - PageArray → [{id, title, path}, ...]
     * - Pagefiles/Pageimages → count or full metadata
     * - RepeaterPageArray → array of formatted field objects
     * - WireArray → plain array
     * - DateTime → ISO 8601 string
     * - Scalars → as-is
     * 
     * @param \ProcessWire\Field $field        Field definition
     * @param mixed              $value        Field value
     * @param bool               $includeFiles Include file details
     * @return mixed Formatted value
     */
    private function formatFieldValue($field, $value, bool $includeFiles = false) {
        // Handle null/empty values
        if ($value === null || $value === '') {
            return null;
        }
        
        // Handle single page reference
        if ($value instanceof \ProcessWire\Page) {
            return $this->formatPageReference($value);
        }
        
        // Handle page array (multi-page reference)
        if ($value instanceof \ProcessWire\PageArray) {
            $pages = [];
            foreach ($value as $p) {
                $pages[] = $this->formatPageReference($p);
            }
            return $pages;
        }
        
        // Handle images and files
        if ($value instanceof \ProcessWire\Pagefiles || $value instanceof \ProcessWire\Pageimages) {
            return $this->formatFiles($value, $includeFiles);
        }
        
        // Handle repeater/matrix fields
        if ($value instanceof \ProcessWire\RepeaterPageArray) {
            return $this->formatRepeater($value, $includeFiles);
        }
        
        // Handle generic WireArray
        if ($value instanceof \ProcessWire\WireArray) {
            return $value->getArray();
        }
        
        // Handle DateTime objects
        if ($value instanceof \DateTime) {
            return $value->format('c');  // ISO 8601 format
        }
        
        // Default: return scalars as-is (strings, numbers, booleans)
        return $value;
    }
    
    /**
     * Format a page reference for output
     * 
     * Converts a Page object to a minimal reference array
     * containing just id, title, and path.
     * 
     * @param \ProcessWire\Page $page Page to format
     * @return array|null Page reference or null if invalid
     */
    private function formatPageReference($page): ?array {
        if (!$page || !$page->id) {
            return null;
        }
        
        return [
            'id' => $page->id,
            'title' => $page->title,
            'path' => $page->path,
        ];
    }
    
    /**
     * Format files/images for output
     * 
     * By default, returns count and filenames. When $includeFiles is true,
     * returns full metadata including URL, size, and (for images) dimensions.
     * 
     * @param \ProcessWire\Pagefiles $files        Files to format
     * @param bool                   $includeFiles Include full metadata
     * @return array File info with count, filenames, and optionally details
     */
    private function formatFiles($files, bool $includeFiles = false): array {
        $count = $files->count();
        
        // Always get filenames
        $filenames = [];
        foreach ($files as $file) {
            $filenames[] = $file->name;
        }
        
        // Default: return count + filenames (lightweight)
        if (!$includeFiles) {
            return [
                '_count' => $count,
                '_files' => $filenames,
            ];
        }
        
        // Return full file metadata when requested
        $details = [];
        foreach ($files as $file) {
            $fileData = [
                'filename' => $file->name,
                'url' => $file->url,
                'size' => $file->filesize,
                'description' => $file->description ?: null,
            ];
            
            // Add image-specific properties
            if ($file instanceof \ProcessWire\Pageimage) {
                $fileData['width'] = $file->width;
                $fileData['height'] = $file->height;
            }
            
            $details[] = $fileData;
        }
        
        return [
            '_count' => $count,
            '_files' => $filenames,
            '_details' => $details,
        ];
    }
    
    /**
     * Format repeater items for output
     * 
     * Repeater fields contain nested pages with their own fields.
     * This method recursively formats each repeater item's fields.
     * 
     * @param \ProcessWire\RepeaterPageArray $repeater     Repeater items
     * @param bool                           $includeFiles Include file details
     * @return array Array with count and formatted repeater items
     */
    private function formatRepeater($repeater, bool $includeFiles = false): array {
        $items = [];
        
        foreach ($repeater as $item) {
            $itemData = [
                '_type' => $this->getRepeaterType($item),
            ];
            
            foreach ($item->template->fields as $field) {
                // Skip internal repeater fields
                if (strpos($field->name, 'repeater_') === 0) {
                    continue;
                }
                $itemData[$field->name] = $this->formatFieldValue($field, $item->get($field->name), $includeFiles);
            }
            $items[] = $itemData;
        }
        
        return [
            '_count' => count($items),
            '_items' => $items,
        ];
    }
    
    /**
     * Get the type name for a repeater matrix item
     * 
     * @param \ProcessWire\Page $item Repeater item
     * @return string|null Type name or null for regular repeaters
     */
    private function getRepeaterType($item): ?string {
        $typeField = $item->get('repeater_matrix_type');
        return $typeField ? (string) $typeField : null;
    }
}
