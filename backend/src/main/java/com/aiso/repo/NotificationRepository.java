package com.aiso.repo;

import com.aiso.domain.Notification;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface NotificationRepository extends JpaRepository<Notification, Long> {
    List<Notification> findByRecipientIdOrderByCreatedAtDesc(String recipientId, Pageable pageable);

    List<Notification> findByRecipientIdAndReadAtIsNull(String recipientId);

    long countByRecipientIdAndReadAtIsNull(String recipientId);

    long countByDeliveryStatus(String deliveryStatus);

    List<Notification> findByDeliveryStatusOrderByCreatedAtDesc(String deliveryStatus, Pageable pageable);
}
