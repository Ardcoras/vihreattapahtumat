<?php

namespace Drupal\vihreattapahtumat_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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
        foreach (['title', 'start', 'organiser'] as $field) {
            if (empty($data[$field])) {
                return ['error' => ['index' => $index, 'message' => "Missing required field: $field"]];
            }
        }

        // Resolve organiser (must exist).
        $organiser = $this->findNodeByTitle($data['organiser'], 'organisation');
        if (!$organiser) {
            return ['error' => ['index' => $index, 'message' => 'Organiser "' . $data['organiser'] . '" not found']];
        }

        // Resolve event type (must exist, if provided).
        $event_type = NULL;
        if (!empty($data['event_type'])) {
            $event_type = $this->findTermByName($data['event_type'], 'event_type');
            if (!$event_type) {
                return ['error' => ['index' => $index, 'message' => 'Event type "' . $data['event_type'] . '" not found']];
            }
        }

        // Resolve place (auto-create if not found).
        $place = NULL;
        if (!empty($data['place_name'])) {
            // Municipality is required when place is provided.
            if (empty($data['municipality'])) {
                return ['error' => ['index' => $index, 'message' => 'Municipality is required when place_name is provided']];
            }

            $municipality = $this->findTermByName($data['municipality'], 'municipality');
            if (!$municipality) {
                return ['error' => ['index' => $index, 'message' => 'Municipality "' . $data['municipality'] . '" not found']];
            }

            $place = $this->findOrCreatePlace($data['place_name'], $municipality, $data['street_address'] ?? '');
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
     * Finds a node by title and bundle.
     */
    private function findNodeByTitle(string $title, string $bundle): ?Node
    {
        $nids = $this->entityTypeManager()
            ->getStorage('node')
            ->getQuery()
            ->condition('type', $bundle)
            ->condition('title', $title)
            ->accessCheck(FALSE)
            ->range(0, 1)
            ->execute();

        if (empty($nids)) {
            return NULL;
        }

        return Node::load(reset($nids));
    }

    /**
     * Finds a taxonomy term by name and vocabulary.
     */
    private function findTermByName(string $name, string $vid): ?Term
    {
        $tids = $this->entityTypeManager()
            ->getStorage('taxonomy_term')
            ->getQuery()
            ->condition('vid', $vid)
            ->condition('name', $name)
            ->accessCheck(FALSE)
            ->range(0, 1)
            ->execute();

        if (empty($tids)) {
            return NULL;
        }

        return Term::load(reset($tids));
    }

    /**
     * Finds or creates a place taxonomy term.
     */
    private function findOrCreatePlace(string $name, Term $municipality, string $street_address): Term
    {
        // Search by name + municipality.
        $tids = $this->entityTypeManager()
            ->getStorage('taxonomy_term')
            ->getQuery()
            ->condition('vid', 'place')
            ->condition('name', $name)
            ->condition('field_municipality', $municipality->id())
            ->accessCheck(FALSE)
            ->range(0, 1)
            ->execute();

        if (!empty($tids)) {
            return Term::load(reset($tids));
        }

        // Auto-create the place.
        $term = Term::create([
            'vid' => 'place',
            'name' => $name,
            'field_municipality' => ['target_id' => $municipality->id()],
            'field_street_address' => $street_address,
        ]);
        $term->save();

        return $term;
    }

    /**
     * Returns a JSON error response.
     */
    private function errorResponse(string $message, int $code): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => $message], $code);
    }

}
