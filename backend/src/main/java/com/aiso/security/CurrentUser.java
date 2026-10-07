package com.aiso.security;

import com.aiso.domain.Role;
import org.springframework.security.core.Authentication;

/** The authenticated principal: identity and role come from the database, never from token claims. */
public record CurrentUser(String id, Role role) {

    public static CurrentUser from(Authentication auth) {
        return (CurrentUser) auth.getPrincipal();
    }

    public boolean isOwner() {
        return role == Role.OWNER;
    }

    public boolean isOwnerOrManager() {
        return role == Role.OWNER || role == Role.MANAGER;
    }
}
