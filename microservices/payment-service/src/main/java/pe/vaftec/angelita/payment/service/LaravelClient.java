package pe.vaftec.angelita.payment.service;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.MediaType;
import org.springframework.stereotype.Component;
import org.springframework.web.client.RestClient;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.HexFormat;
import java.util.Map;
import java.util.UUID;

@Component
public class LaravelClient {
    private final RestClient client;
    private final ObjectMapper mapper;
    private final String secret;

    public LaravelClient(
        RestClient.Builder builder,
        ObjectMapper mapper,
        @Value("${laravel.base-url}") String baseUrl,
        @Value("${payment.shared-secret}") String secret
    ) {
        this.client = builder.baseUrl(baseUrl).build();
        this.mapper = mapper;
        this.secret = secret;
    }

    public JsonNode complete(Long orderId, Map<String, Object> payload) {
        return postSigned("api/internal/payment/orders/" + orderId + "/complete", payload);
    }

    public JsonNode fail(Long orderId, Map<String, Object> payload) {
        return postSigned("api/internal/payment/orders/" + orderId + "/fail", payload);
    }

    private JsonNode postSigned(String path, Map<String, Object> payload) {
        try {
            String body = mapper.writeValueAsString(payload);
            String timestamp = String.valueOf(Instant.now().getEpochSecond());
            String nonce = UUID.randomUUID().toString().replace("-", "");
            String canonical = String.join("\n", timestamp, nonce, "POST", path, body);
            String signature = hmac(canonical);

            return client.post()
                .uri("/" + path)
                .contentType(MediaType.APPLICATION_JSON)
                .accept(MediaType.APPLICATION_JSON)
                .header("X-Payment-Timestamp", timestamp)
                .header("X-Payment-Nonce", nonce)
                .header("X-Payment-Signature", signature)
                .body(body)
                .retrieve()
                .body(JsonNode.class);
        } catch (Exception e) {
            throw new IllegalStateException("Laravel callback failed", e);
        }
    }

    private String hmac(String data) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
        return HexFormat.of().formatHex(mac.doFinal(data.getBytes(StandardCharsets.UTF_8)));
    }
}
