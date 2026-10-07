package com.aiso.security;

import com.aiso.domain.AppUser;
import com.aiso.repo.UserRepository;
import org.springframework.core.convert.converter.Converter;
import org.springframework.security.authentication.UsernamePasswordAuthenticationToken;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.oauth2.jwt.Jwt;
import org.springframework.security.oauth2.server.resource.InvalidBearerTokenException;
import org.springframework.stereotype.Component;
import org.springframework.security.authentication.AbstractAuthenticationToken;

import java.util.List;

/**
 * Re-reads the user on every request so deactivating a user or changing a role takes effect immediately,
 * even for tokens that are still unexpired.
 */
@Component
public class UserJwtConverter implements Converter<Jwt, AbstractAuthenticationToken> {

    private final UserRepository users;

    public UserJwtConverter(UserRepository users) {
        this.users = users;
    }

    @Override
    public AbstractAuthenticationToken convert(Jwt jwt) {
        AppUser user = users.findById(jwt.getSubject())
                .filter(AppUser::isActive)
                .orElseThrow(() -> new InvalidBearerTokenException("User is unknown or disabled"));
        return new UsernamePasswordAuthenticationToken(
                new CurrentUser(user.getId(), user.getRole()),
                jwt,
                List.of(new SimpleGrantedAuthority("ROLE_" + user.getRole().name())));
    }
}
