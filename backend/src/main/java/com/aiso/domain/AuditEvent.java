package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;
import org.hibernate.annotations.Immutable;

import java.time.Instant;

/** Append-only audit record. Never updated or deleted by the application. */
@Entity
@Immutable
@Table(name = "audit_event")
@Getter
@Setter
@NoArgsConstructor
public class AuditEvent {
    @Id
    @Column(length = 36)
    private String eventId;

    @Column(nullable = false)
    private Instant occurredAt;

    private String actorId;

    @Column(name = "action_name", nullable = false)
    private String action;

    @Column(nullable = false)
    private String entityType;

    private String entityId;

    @Column(length = 4000)
    private String previousValue;

    @Column(length = 4000)
    private String newValue;

    @Column(length = 1000)
    private String reason;

    @Column(name = "project_id")
    private String relatedProjectId;
}
