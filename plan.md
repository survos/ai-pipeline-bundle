# Symfony AI 0.6 Upgrade Plan

## Completed

- Updated `composer.json` AI dependencies to `^0.6`:
  - `symfony/ai-bundle`
  - `symfony/ai-agent`
  - `symfony/ai-open-ai-platform`
  - `symfony/ai-mistral-platform`
- Raised Symfony constraints to `^7.3|^8.0` to match `symfony/ai-bundle:^0.6` requirements:
  - `symfony/framework-bundle`
  - `symfony/http-client`
  - `symfony/twig-bundle`
  - `extra.symfony.require`
- Added support for new 0.6 token usage metadata fields:
  - `cache_creation`
  - `cache_read`
  - Implemented in:
    - `src/Task/AbstractVisionTask.php`
    - `src/Task/OcrTask.php`
- Removed unused import in `src/Task/AbstractVisionTask.php`.
- Updated docs reference from Symfony AI v0.5.x to v0.6.x in `docs/terreract.md`.

## Validation Performed

- `composer validate --no-check-publish` (bundle root) passed.
- `php -l` checks passed for:
  - `src/Task/AbstractVisionTask.php`
  - `src/Task/OcrTask.php`

## Migration Notes

- No additional mandatory runtime changes were identified for this bundle’s current usage patterns.
- Symfony AI remains experimental; expect more API evolution across minor versions.

## Suggested Improvements

1. Add a focused test to assert `_tokens` includes:
   - `prompt`, `completion`, `total`, `cached`, `cache_creation`, `cache_read`
2. Extract token metadata mapping into a shared helper to reduce duplication between task classes.
3. Decide long-term compatibility strategy:
   - Keep strict `^0.6` + Symfony `^7.3|^8.0`, or
   - Support dual range `^0.5|^0.6` (higher maintenance burden).
