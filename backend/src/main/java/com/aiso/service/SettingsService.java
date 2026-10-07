package com.aiso.service;

import com.aiso.domain.SystemSetting;
import com.aiso.repo.SystemSettingRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.time.Instant;

@Service
public class SettingsService {

    private final SystemSettingRepository repo;

    public SettingsService(SystemSettingRepository repo) {
        this.repo = repo;
    }

    @Transactional
    public SystemSetting get() {
        return repo.findById(1).orElseGet(() -> repo.save(new SystemSetting()));
    }

    /** Saves the settings and increments the configuration version. */
    @Transactional
    public SystemSetting saveChanged(SystemSetting s) {
        s.setConfigurationVersion(s.getConfigurationVersion() + 1);
        s.setUpdatedAt(Instant.now());
        return repo.save(s);
    }
}
