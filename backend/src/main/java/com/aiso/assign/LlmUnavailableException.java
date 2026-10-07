package com.aiso.assign;

/** The LLM strategy could not produce a usable answer (no key, API failure, refusal, unparsable output). */
public class LlmUnavailableException extends RuntimeException {
    public LlmUnavailableException(String message) {
        super(message);
    }

    public LlmUnavailableException(String message, Throwable cause) {
        super(message, cause);
    }
}
