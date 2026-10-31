import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * Values read from the Laravel source, so tests fail when the API gains a code, parameter or
 * value that this server's descriptions don't mention (the MCP server is a client of v1 and
 * must describe it truthfully).
 */
const api = (path: string) => readFileSync(fileURLToPath(new URL(`../../api/${path}`, import.meta.url)), 'utf8');

function enumValues(path: string): string[] {
  return [...api(path).matchAll(/^\s*case \w+ = '([^']+)';/gm)].map((m) => m[1]!);
}

export const errorCodes = () => enumValues('app/Support/Errors/ErrorCode.php');
export const formats = () => enumValues('app/Enums/AdFormat.php');
export const statuses = () => enumValues('app/Enums/AdStatus.php');
export const sorts = () => enumValues('app/Search/SearchSort.php');

export function nicheSlugs(): string[] {
  return [...api('app/Support/NicheCatalog.php').matchAll(/^\s*'([a-z-]+)' => \['name'/gm)].map((m) => m[1]!);
}

export function searchParams(): string[] {
  const list = /public const PARAMS = \[([^\]]*)\]/.exec(api('app/Search/SearchRequestParser.php'))![1]!;
  return [...list.matchAll(/'([^']+)'/g)].map((m) => m[1]!);
}

/** "retryable" per code, from ErrorCode::retryable(). */
export function retryableCodes(): string[] {
  const body = /function retryable\(\): bool\s*\{([\s\S]*?)\n    \}/.exec(api('app/Support/Errors/ErrorCode.php'))![1]!;
  const line = /([^\n]*)=> true/.exec(body)![1]!;
  const names = [...line.matchAll(/self::(\w+)/g)].map((m) => m[1]!);
  const source = api('app/Support/Errors/ErrorCode.php');

  return names.map((name) => new RegExp(`case ${name} = '([^']+)'`).exec(source)![1]!);
}
