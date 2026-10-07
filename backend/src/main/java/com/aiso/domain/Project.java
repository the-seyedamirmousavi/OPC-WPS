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
@Table(name = "project")
@Getter
@Setter
@NoArgsConstructor
public class Project {
    @Id
    @Column(length = 64)
    private String id;

    @Column(nullable = false, length = 200)
    private String name;

    /** 1 = most important. Active projects hold ranks 1..n without gaps. */
    @Column(nullable = false)
    private int priority = 1;

    /** Optional target finish; only used to report tardiness, never to reorder work. */
    private Instant dueDate;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private ProjectStatus status = ProjectStatus.ACTIVE;

    private String description;

    @Column(nullable = false)
    private Instant createdAt = Instant.now();
}
