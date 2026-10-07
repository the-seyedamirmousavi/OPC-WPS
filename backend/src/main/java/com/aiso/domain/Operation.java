package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import jakarta.persistence.Version;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

@Entity
@Table(name = "operation")
@Getter
@Setter
@NoArgsConstructor
public class Operation {
    @Id
    @Column(length = 64)
    private String id;

    @Column(nullable = false, length = 300)
    private String name;

    @Column(nullable = false)
    private String projectId;

    private String itemId;

    @Column(nullable = false)
    private String resourceId;

    /** Fixed executor defined in the OPC sheet (hard constraint for assignment). */
    private String responsibleUserId;

    /** All times in hours. */
    private double preparationTime;
    private double transportTime;
    private double setupTime;
    private double directTime;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private OperationStatus status = OperationStatus.NOT_READY;

    private String assignedUserId;
    private String blockReason;

    @Enumerated(EnumType.STRING)
    @Column(length = 20)
    private OperationStatus statusBeforeBlock;

    private int progressPercent;

    private Instant plannedStart;
    /** Baseline due time, fixed when the operation is assigned. Used for delay detection. */
    private Instant plannedEnd;
    private Instant assignedAt;
    private Instant startedAt;
    private Instant completedAt;

    private boolean completionApproved;
    private String completionApprovedBy;
    private String cancelReason;
    private String description;

    @Column(nullable = false)
    private Instant createdAt = Instant.now();

    @Column(nullable = false)
    private Instant updatedAt = Instant.now();

    @Version
    private long version;

    public double totalHours() {
        return preparationTime + transportTime + setupTime + directTime;
    }
}
