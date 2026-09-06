<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Contract-wide constants for the headless front end. The Astro client
 * mirrors the DTO shapes in this namespace; a version bump signals a
 * breaking change that the client must follow.
 */
final class Contract
{
    /** Semantic version of the DTO shapes exposed to front ends; matches the client's `meta.apiVersion` literal. */
    public const VERSION = '1';

    /** REST namespace all controllers in Api/Rest/ register under. */
    public const API_NAMESPACE = 'aiya/core/v1';
}
