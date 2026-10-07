package com.aiso.service;

import com.aiso.domain.AppUser;
import com.aiso.domain.Notification;
import com.aiso.domain.Role;
import com.aiso.repo.NotificationRepository;
import com.aiso.repo.UserRepository;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;
import java.util.List;

/**
 * Stores every notification in the in-app inbox and attempts delivery through the configured messenger platform.
 * A failed external delivery is recorded (delivery_status = FAILED) so it can be detected and reported.
 */
@Service
public class NotificationService {

    private static final Logger log = LoggerFactory.getLogger(NotificationService.class);

    private final NotificationRepository repo;
    private final UserRepository users;
    private final SettingsService settings;
    private final LanguageService lang;

    public NotificationService(NotificationRepository repo, UserRepository users, SettingsService settings,
                               LanguageService lang) {
        this.repo = repo;
        this.users = users;
        this.settings = settings;
        this.lang = lang;
    }

    @Transactional
    public void notifyUser(String userId, String kind, String message, String operationId) {
        if (userId == null) {
            return;
        }
        AppUser user = users.findById(userId).orElse(null);
        if (user == null) {
            return;
        }
        message = lang.forStorage(message);
        Notification n = new Notification();
        n.setRecipientId(userId);
        n.setKind(kind);
        n.setMessage(message.length() > 1000 ? message.substring(0, 1000) : message);
        n.setRelatedOperationId(operationId);
        n.setCreatedAt(Instant.now());
        try {
            deliver(user, message);
            n.setDeliveryStatus("SENT");
        } catch (Exception e) {
            log.warn("Messenger delivery to {} failed: {}", userId, e.getMessage());
            n.setDeliveryStatus("FAILED");
            String err = e.getMessage() == null ? e.getClass().getSimpleName() : e.getMessage();
            n.setDeliveryError(err.length() > 500 ? err.substring(0, 500) : err);
        }
        repo.save(n);
    }

    @Transactional
    public void notifyManagers(String kind, String message, String operationId) {
        List<AppUser> managers = users.findByRoleIn(List.of(Role.MANAGER));
        for (AppUser m : managers) {
            if (m.isActive()) {
                notifyUser(m.getId(), kind, message, operationId);
            }
        }
    }

    /**
     * Messenger connector seam. MVP 0.1 ships only the in-app inbox; any other platform name configured in Settings
     * fails visibly instead of silently dropping the message. Add a connector (e.g. Telegram) here.
     */
    private void deliver(AppUser user, String message) {
        String platform = settings.get().getMessengerPlatform();
        if (platform == null || platform.isBlank() || "IN_APP".equalsIgnoreCase(platform)) {
            return;
        }
        throw new IllegalStateException("No connector installed for messenger platform " + platform);
    }
}
