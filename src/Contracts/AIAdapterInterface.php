/**
 * AI Adapter Interface
 * Model-agnostic AI operations
 */
interface AIAdapterInterface {
    public function configure(array $config): void;
    public function summarize(string $content, array $options): string;
    public function extractEntities(string $content): array;
    public function extractRelationships(string $content): array;
    public function scoreEpistemicConfidence(string $claim, array $context): array;
    public function processMultimodal(array $inputs): array;
    public function getModelInfo(): array;
}