package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

@Entity
@Table(name = "app_user")
@Getter
@Setter
@NoArgsConstructor
public class AppUser {
    @Id
    @Column(length = 64)
    private String id;

    @Column(nullable = false, length = 200)
    private String fullName;

    @Enumerated(EnumType.STRING)
    @Column(name = "user_role", nullable = false, length = 20)
    private Role role;

    private String messengerId;
    private boolean active = true;
    private String contactInfo;

    @Column(nullable = false)
    private String passwordHash;

    private boolean mustChangePassword;

    @Column(nullable = false)
    private Instant createdAt = Instant.now();
}
