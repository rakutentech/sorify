<?php

namespace App\Services\Agent;

use RuntimeException;

/**
 * Raised by a Prism tool handler when the wrapped MCP tool reported an
 * error. The message is the tool's own error output; the tool's failed
 * handler relays it back to the LLM (as a failed tool result) so the model
 * can react and retry instead of the turn dying.
 */
class AgentToolError extends RuntimeException {}
