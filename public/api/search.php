<?php

/**
 * What the search box looks through beyond the cards (labels, catalogue
 * numbers, tracklists, credits, notes), as { id: text }. Kept out of the cards
 * because it is most of their weight, and most visits never search.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

cached_json_response('search', fn () => (object) public_search_texts());
