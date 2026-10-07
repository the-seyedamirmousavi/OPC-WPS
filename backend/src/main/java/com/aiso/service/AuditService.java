package com.aiso.service;

import com.aiso.domain.AuditEvent;
import com.aiso.repo.AuditEventRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.UUID;

/** Writes append-only audit events (Event_ID, Timestamp, Actor_ID, Action, Entity, Previous/New value, Reason, Project). */
@Service
public class AuditService {

    private final AuditEventRepository repo;
    private final SettingsService settings;
    private final LanguageService lang;

    public AuditService(AuditEventRepository repo, SettingsService settings, LanguageService lang) {
        this.repo = repo;
        this.settings = settings;
        this.lang = lang;
    }

    @Transactional
    public void record(String actorId, String action, String entityType, String entityId,
                       Object previous, Object next, String reason) {
        AuditEvent e = new AuditEvent();
        e.setEventId(UUID.randomUUID().toString());
        e.setOccurredAt(Instant.now());
        e.setActorId(actorId);
        e.setAction(action);
        e.setEntityType(entityType);
        e.setEntityId(entityId);
        e.setPreviousValue(clip(previous, 4000));
        e.setNewValue(clip(next, 4000));
        e.setReason(clip(reason == null ? null : lang.forStorage(reason), 1000));
        e.setRelatedProjectId(settings.get().getProjectId());
        repo.save(e);
    }

    private static String clip(Object value, int max) {
        if (value == null) {
            return null;
        }
        String s = String.valueOf(value);
        return s.length() <= max ? s : s.substring(0, max - 3) + "...";
    }
}
