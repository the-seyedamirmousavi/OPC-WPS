package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.EnumType;
import jakarta.persistence.Enumerated;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

@Entity
@Table(name = "assignment_proposal")
@Getter
@Setter
@NoArgsConstructor
public class AssignmentProposal {
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(nullable = false, length = 36)
    private String runId;

    @Column(nullable = false)
    private String operationId;

    @Column(nullable = false)
    private String userId;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private AssignmentMode source;

    @Column(length = 2000)
    private String reason;

    private Instant plannedStart;
    private Instant plannedEnd;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false, length = 20)
    private ProposalStatus status = ProposalStatus.PENDING;

    private String decisionNote;

    @Column(nullable = false)
    private Instant createdAt = Instant.now();

    private Instant decidedAt;
    private String decidedBy;
}
