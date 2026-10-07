package com.aiso.security;

import com.aiso.config.AisoProperties;
import com.aiso.domain.AppUser;
import org.springframework.security.oauth2.jose.jws.MacAlgorithm;
import org.springframework.security.oauth2.jwt.JwsHeader;
import org.springframework.security.oauth2.jwt.JwtClaimsSet;
import org.springframework.security.oauth2.jwt.JwtEncoder;
import org.springframework.security.oauth2.jwt.JwtEncoderParameters;
import org.springframework.stereotype.Service;

import java.time.Duration;
import java.time.Instant;

@Service
public class TokenService {

    private final JwtEncoder encoder;
    private final AisoProperties props;

    public TokenService(JwtEncoder encoder, AisoProperties props) {
        this.encoder = encoder;
        this.props = props;
    }

    public String issue(AppUser user) {
        Instant now = Instant.now();
        JwtClaimsSet claims = JwtClaimsSet.builder()
                .issuer("aiso")
                .subject(user.getId())
                .issuedAt(now)
                .expiresAt(now.plus(Duration.ofMinutes(props.jwt().ttlMinutes())))
                .build();
        return encoder.encode(JwtEncoderParameters.from(JwsHeader.with(MacAlgorithm.HS256).build(), claims)).getTokenValue();
    }

    public int ttlSeconds() {
        return props.jwt().ttlMinutes() * 60;
    }
}
