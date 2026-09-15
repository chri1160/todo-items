<?php

namespace Timot\TodoItems\Support;

/**
 * Who "this agent" is, for anything several concurrent agents share.
 *
 * Several Claude sessions can work in one checkout, so any coordination
 * surface — a TODO claim today, anything else later — needs a stable per-agent
 * name that isn't a pid (an agent outlives every process it spawns) and isn't
 * the working tree (they all share one). The session id is exactly that:
 * constant for the life of a session, different for every other agent, and
 * already in the environment, so nothing has to be provisioned to get it.
 *
 * Short by design — eight hex characters is enough to tell agents apart in a
 * table row, and the full uuid is noise there.
 */
class AgentSession
{
    /** Stand-in when no session id is set — a human at a terminal. */
    public const MANUAL = 'manual';

    public static function key(): string
    {
        $session = getenv('CLAUDE_CODE_SESSION_ID') ?: '';

        return $session !== '' ? substr($session, 0, 8) : self::MANUAL;
    }

    /** True when the key names this agent (or the terminal we're running in). */
    public static function isSelf(?string $key): bool
    {
        return $key !== null && $key === self::key();
    }
}
