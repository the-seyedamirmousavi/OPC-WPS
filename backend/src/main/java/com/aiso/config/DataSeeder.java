package com.aiso.config;

import com.aiso.domain.AppUser;
import com.aiso.domain.Role;
import com.aiso.domain.SystemSetting;
import com.aiso.repo.UserRepository;
import com.aiso.service.SettingsService;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.boot.ApplicationArguments;
import org.springframework.boot.ApplicationRunner;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Component;
import org.springframework.transaction.annotation.Transactional;

/** On an empty database creates the OWNER, a MANAGER and the three executive users (accounts are independent). */
@Component
public class DataSeeder implements ApplicationRunner {

    private static final Logger log = LoggerFactory.getLogger(DataSeeder.class);
    private static final String DEFAULT_PASSWORD = "ChangeMe!123";

    private final UserRepository users;
    private final PasswordEncoder encoder;
    private final SettingsService settings;
    private final AisoProperties props;

    public DataSeeder(UserRepository users, PasswordEncoder encoder, SettingsService settings, AisoProperties props) {
        this.users = users;
        this.encoder = encoder;
        this.settings = settings;
        this.props = props;
    }

    @Override
    @Transactional
    public void run(ApplicationArguments args) {
        if (!props.seed().enabled() || users.count() > 0) {
            return;
        }
        String password = props.seed().password();
        add("owner", "System Owner", Role.OWNER, password);
        add("manager", "Project Manager", Role.MANAGER, password);
        add("user1", "Executive User 1", Role.USER_1, password);
        add("user2", "Executive User 2", Role.USER_2, password);
        add("user3", "Executive User 3", Role.USER_3, password);

        SystemSetting s = settings.get();
        s.setOwnerId("owner");
        s.setManagerId("manager");
        if (props.seed().language() != null && !props.seed().language().isBlank()) {
            s.setLanguage(props.seed().language());
        }
        settings.saveChanged(s);

        log.warn("Seeded users owner, manager, user1, user2, user3.");
        if (DEFAULT_PASSWORD.equals(password)) {
            log.warn("They use the DEFAULT password. Set AISO_SEED_PASSWORD before the first start, or change the passwords now.");
        }
    }

    private void add(String id, String name, Role role, String password) {
        AppUser u = new AppUser();
        u.setId(id);
        u.setFullName(name);
        u.setRole(role);
        u.setPasswordHash(encoder.encode(password));
        u.setMustChangePassword(false);
        users.save(u);
    }
}
