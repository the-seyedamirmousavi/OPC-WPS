package com.aiso.repo;

import com.aiso.domain.AuditEvent;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface AuditEventRepository extends JpaRepository<AuditEvent, String> {
    List<AuditEvent> findAllByOrderByOccurredAtDesc(Pageable pageable);

    List<AuditEvent> findByEntityTypeAndEntityIdOrderByOccurredAtDesc(String entityType, String entityId, Pageable pageable);

    List<AuditEvent> findByActorIdOrderByOccurredAtDesc(String actorId, Pageable pageable);
}
