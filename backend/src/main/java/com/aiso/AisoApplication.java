package com.aiso;

import com.aiso.config.AisoProperties;
import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.boot.context.properties.EnableConfigurationProperties;

@SpringBootApplication
@EnableConfigurationProperties(AisoProperties.class)
public class AisoApplication {
    public static void main(String[] args) {
        SpringApplication.run(AisoApplication.class, args);
    }
}
