package com.aiso.domain;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;
import lombok.Getter;
import lombok.NoArgsConstructor;
import lombok.Setter;

import java.time.Instant;

@Entity
@Table(name = "notification")
@Getter
@Setter
@NoArgsConstructor
public class Notification {
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    @Column(nullable = false)
    private String recipientId;

    @Column(nullable = false, length = 40)
    private String kind;

    @Column(nullable = false, length = 1000)
    private String message;

    private String relatedOperationId;

    @Column(nullable = false)
    private Instant createdAt = Instant.now();

    private Instant readAt;

    /** SENT or FAILED (delivery to the external messenger). The in-app inbox is always populated. */
    @Column(nullable = false, length = 20)
    private String deliveryStatus = "SENT";

    private String deliveryError;
}
