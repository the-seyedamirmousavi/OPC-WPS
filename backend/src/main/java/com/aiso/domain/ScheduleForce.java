package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

/**
 * A manager FORCE on one operation: it must start at {@code forcedStart} and/or be done by {@code forcedUserId}.
 * The scheduler treats it as a hard constraint; one that has become impossible is reported, never silently dropped.
 */
@Entity
@Table(name = "schedule_force")
@Getter
@Setter
@NoArgsConstructor
public class ScheduleForce {
    @Id
    @Column(length = 64)
    private String operationId;

    private Instant forcedStart;

    @Column(length = 64)
    private String forcedUserId;

    @Column(length = 500)
    private String reason;

    @Column(nullable = false, length = 64)
    private String createdBy;

    @Column(nullable = false)
    private Instant createdAt;
}
