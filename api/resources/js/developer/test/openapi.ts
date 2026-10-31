import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { Ajv, type ValidateFunction } from 'ajv';
import addFormats from 'ajv-formats';
import { parse } from 'yaml';

/**
 * The response schemas of openapi/v1.yaml. The /developer endpoints return exactly what the
 * matching /api/v1 endpoints return (a Pest test checks that), so the page's test fixtures must
 * match these schemas too, or the tests would pass against data the API never sends.
 */
// Vitest runs from api/ (jsdom gives no file URL to resolve against).
const document = parse(readFileSync(resolve(process.cwd(), 'openapi/v1.yaml'), 'utf8'));
const ajv = new Ajv({ strict: false, allErrors: true });
addFormats.default(ajv);
ajv.addSchema({ components: document.components }, 'openapi');

const validators = new Map<string, ValidateFunction>();

export function assertMatchesSchema(schema: string, value: unknown): void {
    let validate = validators.get(schema);
    if (validate === undefined) {
        validate = ajv.compile({ $ref: `openapi#/components/schemas/${schema}` });
        validators.set(schema, validate);
    }
    if (!validate(value)) {
        throw new Error(`Test fixture does not match openapi/v1.yaml ${schema}: ${ajv.errorsText(validate.errors)}\n${JSON.stringify(value)}`);
    }
}
