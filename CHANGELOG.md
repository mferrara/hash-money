# Changelog

All notable changes to `hash-money` will be documented in this file.

## [1.0.0] - 2025-XX-XX

### Initial Release

- **Perceptual Hash (pHash)**: DCT-based algorithm for robust image comparison
- **Difference Hash (dHash)**: Gradient-based algorithm for fast image comparison
- **Color Histogram Hash**: HSV-based color distribution analysis with grayscale detection
- **MashedHash**: Comprehensive 64-bit fingerprint combining 11 image characteristics
- **HashValue Object**: Type-safe value object that encapsulates hash value, bit size, and algorithm
- **Configurable Hash Sizes**: Support for 8, 16, 32, and 64-bit hashes for spatial algorithms
- **Type Safety**: Runtime validation prevents comparing incompatible hashes
- **High Performance**: Optimized VIPS operations with memory-efficient pixel extraction
- **Strategy Pattern**: Clean internal architecture for extensibility
- **Linear Color Space Processing**: More accurate perceptual comparisons
- **Automatic EXIF Orientation**: Handles rotated images correctly
- **Alpha Channel Support**: Proper handling with white background flattening
- **Comprehensive Test Suite**: Full coverage for reliability
- **Example Scripts**: Included examples for testing and benchmarking
