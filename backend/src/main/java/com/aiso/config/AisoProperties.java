package com.aiso.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties(prefix = "aiso")
public record AisoProperties(Jwt jwt, Seed seed, Llm llm, Assignment assignment) {

    public record Jwt(String secret, int ttlMinutes) {
    }

    public record Seed(boolean enabled, String password, String language) {
    }

    public record Llm(String apiKey, String model, int timeoutSeconds, int maxTokens, boolean fallbackToAlgorithm) {
        public boolean configured() {
            return apiKey != null && !apiKey.isBlank() && model != null && !model.isBlank();
        }
    }

    public record Assignment(double responsibleBonusHours) {
    }
}
