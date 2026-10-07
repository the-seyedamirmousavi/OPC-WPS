package com.aiso.service;

import jakarta.servlet.http.HttpServletRequest;
import org.springframework.stereotype.Service;
import org.springframework.web.context.request.RequestContextHolder;
import org.springframework.web.context.request.ServletRequestAttributes;

/**
 * Chooses the language of text the system produces.
 * <ul>
 *   <li><b>Request language</b> - for answers to one HTTP call (errors, import findings, Excel downloads): the
 *       {@code Accept-Language} header (the web app sends the user's UI language), else the system language.</li>
 *   <li><b>System language</b> - for text that is stored and shown to several people later (notifications, assignment
 *       reasons, history notes): the {@code language} setting chosen by the owner.</li>
 * </ul>
 */
@Service
public class LanguageService {

    private final SettingsService settings;

    public LanguageService(SettingsService settings) {
        this.settings = settings;
    }

    public boolean systemIsPersian() {
        return "fa".equalsIgnoreCase(settings.get().getLanguage());
    }

    /** True when the current HTTP request asks for Persian (or sends no preference and the system language is Persian). */
    public boolean requestIsPersian() {
        if (RequestContextHolder.getRequestAttributes() instanceof ServletRequestAttributes a) {
            HttpServletRequest req = a.getRequest();
            String h = req.getHeader("Accept-Language");
            if (h != null && !h.isBlank()) {
                return h.trim().toLowerCase().startsWith("fa");
            }
        }
        return systemIsPersian();
    }

    /** Text for the current request. */
    public String forRequest(String text) {
        return requestIsPersian() ? FaTranslator.translate(text) : text;
    }

    /** Text that will be stored. */
    public String forStorage(String text) {
        return systemIsPersian() ? FaTranslator.translate(text) : text;
    }
}
