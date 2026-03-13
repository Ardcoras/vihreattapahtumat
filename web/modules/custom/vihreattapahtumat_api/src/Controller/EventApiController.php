<?php

namespace Drupal\vihreattapahtumat_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the event creation API.
 */
class EventApiController extends ControllerBase
{

    /**
     * Creates one or more events from a JSON request.
     */
    public function createEvents(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), TRUE);
        if ($content === NULL) {
            return $this->errorResponse('Invalid JSON', 400);
        }

        // Support both single event and batch.
        $events = isset($content['events']) ? $content['events'] : [$content];
        $created = [];
        $errors = [];

        foreach ($events as $index => $data) {
            $result = $this->processEvent($data, $index);
            if (isset($result['error'])) {
                $errors[] = $result['error'];
            } else {
                $created[] = $result;
            }
        }

        if (!empty($errors)) {
            return new JsonResponse(['status' => 'error', 'errors' => $errors], 400);
        }

        return new JsonResponse(['status' => 'ok', 'created' => $created], 200);
    }

    /**
     * Processes a single event from the request data.
     */
    private function processEvent(array $data, int $index): array
    {
        // Validate required fields.
        foreach (['title', 'start', 'organiser_id'] as $field) {
            if (empty($data[$field])) {
                return ['error' => ['index' => $index, 'message' => "Missing required field: $field"]];
            }
        }

        // Resolve organiser by ID (must exist and be organisation).
        $organiser = $this->findNodeByIdAndBundle((int) $data['organiser_id'], 'organisation');
        if (!$organiser) {
            return ['error' => ['index' => $index, 'message' => 'Organiser with ID "' . $data['organiser_id'] . '" not found']];
        }

        // Resolve event type (must exist, if provided).
        $event_type = NULL;
        if (!empty($data['event_type'])) {
            $event_type = $this->findTermByName($data['event_type'], 'event_type');
            if (!$event_type) {
                return ['error' => ['index' => $index, 'message' => 'Event type "' . $data['event_type'] . '" not found']];
            }
        }

        // Resolve place by ID (optional).
        $place = NULL;
        if (!empty($data['place_id'])) {
            $place = $this->findTermByIdAndVocabulary((int) $data['place_id'], 'place');
            if (!$place) {
                return ['error' => ['index' => $index, 'message' => 'Place with ID "' . $data['place_id'] . '" not found']];
            }
        }

        // Validate start date format.
        try {
            $start = new \DateTime($data['start']);
        } catch (\Exception $e) {
            return ['error' => ['index' => $index, 'message' => 'Invalid start date format']];
        }

        // Build the event node.
        $values = [
            'type' => 'event',
            'title' => $data['title'],
            'status' => 1,
            'field_event_datetime' => [
                'value' => $start->format('Y-m-d\TH:i:s'),
            ],
            'field_event_organiser' => ['target_id' => $organiser->id()],
        ];

        // Optional end date.
        if (!empty($data['end'])) {
            try {
                $end = new \DateTime($data['end']);
                $values['field_event_datetime']['end_value'] = $end->format('Y-m-d\TH:i:s');
            } catch (\Exception $e) {
                return ['error' => ['index' => $index, 'message' => 'Invalid end date format']];
            }
        }

        // Optional fields.
        if (!empty($data['description'])) {
            $values['field_description'] = ['value' => $data['description'], 'format' => 'plain_text'];
        }
        if ($event_type) {
            $values['field_event_type'] = ['target_id' => $event_type->id()];
        }
        if ($place) {
            $values['field_place'] = ['target_id' => $place->id()];
        }
        if (isset($data['remote'])) {
            $values['field_event_remote'] = (bool) $data['remote'];
        }
        if (isset($data['for_everyone'])) {
            $values['field_event_for_everyone'] = (bool) $data['for_everyone'];
        }

        $node = Node::create($values);
        $node->save();

        $url = $node->toUrl()->toString();
        return ['id' => (int) $node->id(), 'title' => $node->getTitle(), 'url' => $url];
    }

    /**
     * Searches organisers by name.
     */
    public function searchOrganisers(Request $request): JsonResponse
    {
        $name = trim((string) $request->query->get('name', ''));
        if ($name === '') {
            return $this->errorResponse('Missing required query parameter: name', 400);
        }

        $nids = $this->entityTypeManager()
            ->getStorage('node')
            ->getQuery()
            ->condition('type', 'organisation')
            ->condition('title', '%' . $name . '%', 'LIKE')
            ->accessCheck(FALSE)
            ->range(0, 50)
            ->execute();

        $results = [];
        if (!empty($nids)) {
            $nodes = Node::loadMultiple($nids);
            foreach ($nodes as $node) {
                if ($node->bundle() !== 'organisation') {
                    continue;
                }
                $results[] = [
                    'id' => (int) $node->id(),
                    'name' => $node->getTitle(),
                ];
            }
        }

        return new JsonResponse(['status' => 'ok', 'results' => array_values($results)], 200);
    }

    /**
     * Searches places by name or street address.
     */
    public function searchPlaces(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return $this->errorResponse('Missing required query parameter: q', 400);
        }

        $storage = $this->entityTypeManager()->getStorage('taxonomy_term');

        $name_ids = $storage
            ->getQuery()
            ->condition('vid', 'place')
            ->condition('name', '%' . $q . '%', 'LIKE')
            ->accessCheck(FALSE)
            ->range(0, 50)
            ->execute();

        $address_ids = $storage
            ->getQuery()
            ->condition('vid', 'place')
            ->condition('field_street_address', '%' . $q . '%', 'LIKE')
            ->accessCheck(FALSE)
            ->range(0, 50)
            ->execute();

        $tids = array_unique(array_merge($name_ids, $address_ids));
        $results = [];

        if (!empty($tids)) {
            $terms = Term::loadMultiple($tids);
            foreach ($terms as $term) {
                if ($term->bundle() !== 'place') {
                    continue;
                }

                $municipality_name = '';
                if (!$term->get('field_municipality')->isEmpty()) {
                    $municipality = $term->get('field_municipality')->entity;
                    if ($municipality) {
                        $municipality_name = $municipality->label();
                    }
                }

                $results[] = [
                    'id' => (int) $term->id(),
                    'name' => $term->label(),
                    'municipality' => $municipality_name,
                    'street_address' => (string) ($term->get('field_street_address')->value ?? ''),
                ];
            }
        }

        return new JsonResponse(['status' => 'ok', 'results' => array_values($results)], 200);
    }

    /**
     * Creates a place term.
     */
    public function createPlace(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), TRUE);
        if ($data === NULL) {
            return $this->errorResponse('Invalid JSON', 400);
        }

        if (empty($data['name'])) {
            return $this->errorResponse('Missing required field: name', 400);
        }

        $municipality_id = isset($data['municipality_id']) ? (int) $data['municipality_id'] : NULL;
        $municipality_name = isset($data['municipality_name']) ? trim((string) $data['municipality_name']) : NULL;

        if (empty($municipality_id) && ($municipality_name === NULL || $municipality_name === '')) {
            return $this->errorResponse('Either municipality_id or municipality_name is required', 400);
        }

        $municipality_by_id = NULL;
        if (!empty($municipality_id)) {
            $municipality_by_id = $this->findTermByIdAndVocabulary($municipality_id, 'municipality');
            if (!$municipality_by_id) {
                return $this->errorResponse('Municipality with provided municipality_id not found', 400);
            }
        }

        $municipality_by_name = NULL;
        if ($municipality_name !== NULL && $municipality_name !== '') {
            $municipality_by_name = $this->findTermByName($municipality_name, 'municipality');
            if (!$municipality_by_name) {
                return $this->errorResponse('Municipality with provided municipality_name not found', 400);
            }
        }

        if ($municipality_by_id && $municipality_by_name && ((int) $municipality_by_id->id() !== (int) $municipality_by_name->id())) {
            return $this->errorResponse('municipality_id and municipality_name refer to different terms', 400);
        }

        $municipality = $municipality_by_id ?: $municipality_by_name;
        if (!$municipality) {
            return $this->errorResponse('Could not resolve municipality', 400);
        }

        $term_values = [
            'vid' => 'place',
            'name' => $data['name'],
            'field_municipality' => ['target_id' => $municipality->id()],
            'field_street_address' => (string) ($data['street_address'] ?? ''),
        ];

        if (!empty($data['description'])) {
            $term_values['description'] = [
                'value' => (string) $data['description'],
                'format' => 'plain_text',
            ];
        }

        $term = Term::create($term_values);
        $term->save();

        return new JsonResponse([
            'status' => 'ok',
            'created' => [
                'id' => (int) $term->id(),
                'name' => $term->label(),
                'municipality_id' => (int) $municipality->id(),
                'municipality_name' => $municipality->label(),
                'street_address' => (string) ($term->get('field_street_address')->value ?? ''),
            ],
        ], 200);
    }

    /**
     * Returns API documentation.
     */
    public function documentation(): Response
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Vihreät tapahtumat API</title>'
            . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.45;margin:2rem;max-width:960px}code,pre{background:#f5f5f5;padding:.1rem .3rem;border-radius:4px}pre{padding:1rem;overflow:auto}h1,h2{margin-top:1.6rem}</style>'
            . '</head><body>'
            . '<h1>Vihreät tapahtumat API (v1)</h1>'
            . '<p>All API endpoints below require authentication via key auth and permission <code>create events via api</code>.</p>'
            . '<h2>1) Create events</h2>'
            . '<p><strong>POST</strong> <code>/api/v1/events</code></p>'
            . '<p>Required fields per event: <code>title</code>, <code>start</code>, <code>organiser_id</code>. Optional: <code>end</code>, <code>description</code>, <code>event_type</code>, <code>place_id</code>, <code>remote</code>, <code>for_everyone</code>.</p>'
            . '<pre>{"title":"Example","start":"2026-03-14T12:00:00","organiser_id":123,"place_id":456}</pre>'
            . '<h2>2) Search organisers</h2>'
            . '<p><strong>GET</strong> <code>/api/v1/organisers/search?name=...</code></p>'
            . '<h2>3) Search places</h2>'
            . '<p><strong>GET</strong> <code>/api/v1/places/search?q=...</code></p>'
            . '<h2>4) Create place</h2>'
            . '<p><strong>POST</strong> <code>/api/v1/places</code></p>'
            . '<p>Required: <code>name</code> and municipality via <code>municipality_id</code> and/or <code>municipality_name</code>. Optional: <code>description</code>, <code>street_address</code>.</p>'
            . '<p>If both municipality fields are provided, they must resolve to the same municipality term. If they point to different terms, request fails with 400.</p>'
            . '<pre>{"name":"Main Hall","municipality_id":12,"municipality_name":"Helsinki","description":"...","street_address":"Street 1"}</pre>'
            . '<h2>Error format</h2>'
            . '<pre>{"status":"error","message":"..."}</pre>'
            . '</body></html>';

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Finds a node by id and bundle.
     */
    private function findNodeByIdAndBundle(int $id, string $bundle): ?Node
    {
        if ($id <= 0) {
            return NULL;
        }

        $node = Node::load($id);
        if (!$node || $node->bundle() !== $bundle) {
            return NULL;
        }

        return $node;
    }

    /**
     * Finds a taxonomy term by id and vocabulary.
     */
    private function findTermByIdAndVocabulary(int $id, string $vid): ?Term
    {
        if ($id <= 0) {
            return NULL;
        }

        $term = Term::load($id);
        if (!$term || $term->bundle() !== $vid) {
            return NULL;
        }

        return $term;
    }

    /**
     * Finds a taxonomy term by name and vocabulary.
     */
    private function findTermByName(string $name, string $vid): ?Term
    {
        $nids = $this->entityTypeManager()
            ->getStorage('taxonomy_term')
            ->getQuery()
            ->condition('vid', $vid)
            ->condition('name', $name)
            ->accessCheck(FALSE)
            ->range(0, 1)
            ->execute();

        if (empty($nids)) {
            return NULL;
        }

        return Term::load(reset($nids));
    }

    /**
     * Returns a JSON error response.
     */
    private function errorResponse(string $message, int $code): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $code);
    }

}
