# PHP Configuration‑Driven Import Framework

Example architecture of a configuration-driven import pipeline for
validating and deploying structured data across multiple environments.

------------------------------------------------------------------------

## Real-World Context

This project demonstrates the architecture of an internal automation
tool used to deploy content updates across multiple production
environments.

The system validates configuration and structured data before executing
transactional updates across multiple database targets. The design
allows new import types to be added through dedicated importer classes
without modifying the core import engine.

------------------------------------------------------------------------

## Architecture Overview

    YAML configuration
          ↓
    ImportEngine
          ↓
    Importer plugin (strategy pattern)
          ↓
    Validation pipeline
          ↓
    Transactional database import
          ↓
    Logging & checksum control

This architecture enables safe and repeatable deployment of structured
content while minimizing operational risk in production environments.

------------------------------------------------------------------------

## Project Structure

    src/     – importer classes and core import engine
    config/  – YAML configuration defining import jobs
    data/    – example structured content files

------------------------------------------------------------------------

## Key Design Principles

-   **Plugin architecture** -- different content types are handled by
    dedicated importer classes.
-   **Validation-first execution** -- all content is validated before
    any database changes occur.
-   **Transactional safety** -- imports run inside database transactions
    to prevent partial updates.
-   **Configuration-driven automation** -- deployments are defined
    through YAML rather than code changes.
-   **Extensibility** -- new import types can be added by implementing
    the importer interface.
