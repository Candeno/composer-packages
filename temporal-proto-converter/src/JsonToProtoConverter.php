<?php

declare(strict_types=1);

namespace Candeno\Temporal;

use Google\Protobuf\Internal\Message;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\Type;
use Temporal\Exception\DataConverterException;

/**
 * Temporal DataConverter that handles proto Message types in both directions.
 *
 * FROM payload (Go → PHP): intercepts json/plain payloads targeting a proto Message subclass
 * and uses mergeFromJsonString() to deserialize them (Go sends json/plain, not json/protobuf).
 *
 * TO payload (PHP → Go): intercepts proto Message values and serializes them as json/plain
 * via serializeToJsonString() so Go can decode them as plain structs.
 * JSON_NUMERIC_CHECK converts proto3 quoted int64 strings (e.g. "1234567890") to bare JSON
 * numbers so Go can unmarshal them into int64 fields without special handling.
 */
final class JsonToProtoConverter implements DataConverterInterface
{
    private DataConverterInterface $inner;

    public function __construct(?DataConverterInterface $inner = null)
    {
        $this->inner = $inner ?? DataConverter::createDefault();
    }

    public function fromPayload(Payload $payload, mixed $type): mixed
    {
        $t = Type::create($type);

        if ($t->isClass() && $this->isJsonPlain($payload) && $this->isProtoMessage($t->getName())) {
            try {
                $reflection = new \ReflectionClass($t->getName());
                /** @var Message $instance */
                $instance = $reflection->newInstance();
                $instance->mergeFromJsonString($payload->getData(), true);

                return $instance;
            } catch (\Throwable $e) {
                throw new DataConverterException(
                    sprintf('Failed to decode json/plain into %s: %s', $t->getName(), $e->getMessage()),
                    $e->getCode(),
                    $e,
                );
            }
        }

        return $this->inner->fromPayload($payload, $type);
    }

    public function toPayload(mixed $value): Payload
    {
        if ($value instanceof Message) {
            // serializeToJsonString() encodes int64 as quoted strings per proto3 JSON spec
            // (e.g. "createdAt": "1772632446"). JSON_NUMERIC_CHECK converts those quoted
            // numeric strings to JSON numbers so Go can unmarshal them into int64 fields.
            // String enum values (e.g. "action") are not numeric so they are left untouched.
            // associative: false keeps objects as stdClass so json_encode always
            // produces {...} even for empty messages — associative: true would turn
            // an empty object into a PHP array and json_encode would emit [] instead of {}.
            $data = json_encode(json_decode($value->serializeToJsonString(), associative: false), JSON_NUMERIC_CHECK);

            $payload = new Payload();
            $payload->setData($data);
            $payload->setMetadata([
                EncodingKeys::METADATA_ENCODING_KEY => EncodingKeys::METADATA_ENCODING_JSON,
            ]);

            return $payload;
        }

        return $this->inner->toPayload($value);
    }

    private function isJsonPlain(Payload $payload): bool
    {
        $meta = $payload->getMetadata();

        return ($meta[EncodingKeys::METADATA_ENCODING_KEY] ?? null) === EncodingKeys::METADATA_ENCODING_JSON;
    }

    private function isProtoMessage(string $className): bool
    {
        try {
            return (new \ReflectionClass($className))->isSubclassOf(Message::class);
        } catch (\ReflectionException) {
            return false;
        }
    }
}
