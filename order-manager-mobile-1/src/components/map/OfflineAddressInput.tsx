import React, { useState, useEffect } from 'react';
import { View, Text, TextInput, StyleSheet, Alert } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { LocalAddressDB } from '../../services/localAddressDB';

interface OfflineAddressInputProps {
    placeholder?: string;
    value?: string;
    onChangeText?: (text: string) => void;
    onAddressSelect?: (address: string) => void;
}

const RECENT_ADDRESSES_KEY = '@recent_addresses';
const MAX_RECENT_ADDRESSES = 10;

/**
 * Offline address input with recent address suggestions
 * Falls back when Google Maps API is unavailable
 */
const OfflineAddressInput: React.FC<OfflineAddressInputProps> = ({
    placeholder = 'Enter address',
    value = '',
    onChangeText,
    onAddressSelect,
}) => {
    const [address, setAddress] = useState(value || '');
    const [recentAddresses, setRecentAddresses] = useState<string[]>([]);
    const [localSuggestions, setLocalSuggestions] = useState<string[]>([]);
    const [showSuggestions, setShowSuggestions] = useState(false);

    // Load recent addresses and local database on component mount
    useEffect(() => {
        loadRecentAddresses();
    }, []);

    const loadRecentAddresses = async () => {
        try {
            const stored = await AsyncStorage.getItem(RECENT_ADDRESSES_KEY);
            if (stored) {
                setRecentAddresses(JSON.parse(stored));
            }
        } catch (error) {
            console.warn('Failed to load recent addresses:', error);
        }
    };

    const saveRecentAddress = async (newAddress: string) => {
        if (!newAddress.trim() || newAddress.length < 10) return;

        try {
            const updated = [
                newAddress,
                ...recentAddresses.filter(addr => addr !== newAddress)
            ].slice(0, MAX_RECENT_ADDRESSES);

            setRecentAddresses(updated);
            await AsyncStorage.setItem(RECENT_ADDRESSES_KEY, JSON.stringify(updated));
        } catch (error) {
            console.warn('Failed to save recent address:', error);
        }
    };

    const handleAddressChange = async (text: string) => {
        setAddress(text);
        onChangeText?.(text);
        
        if (text.length > 2) {
            // Search local database for matching addresses
            try {
                const localMatches = await LocalAddressDB.searchAddresses(text);
                setLocalSuggestions(localMatches.map(addr => addr.fullAddress));
            } catch (error) {
                console.warn('Failed to search local addresses:', error);
            }
            
            // Show suggestions if text matches any addresses
            const recentMatches = recentAddresses.filter(addr => 
                addr.toLowerCase().includes(text.toLowerCase())
            );
            setShowSuggestions((recentMatches.length > 0 || localSuggestions.length > 0));
        } else {
            setShowSuggestions(false);
            setLocalSuggestions([]);
        }
    };

    const handleAddressComplete = () => {
        if (address.trim()) {
            saveRecentAddress(address);
            onAddressSelect?.(address);
            setShowSuggestions(false);
        }
    };

    const selectRecentAddress = (selectedAddress: string) => {
        setAddress(selectedAddress);
        onChangeText?.(selectedAddress);
        onAddressSelect?.(selectedAddress);
        setShowSuggestions(false);
    };

    // Combine recent and local suggestions
    const recentSuggestions = recentAddresses.filter(addr =>
        addr.toLowerCase().includes(address.toLowerCase()) && 
        addr !== address &&
        address.length > 2
    ).slice(0, 2);

    const combinedSuggestions = [
        ...localSuggestions.slice(0, 3),
        ...recentSuggestions
    ].slice(0, 5); // Show max 5 suggestions

    return (
        <View style={styles.container}>
            <TextInput
                style={styles.input}
                placeholder={`${placeholder} (Manual Entry)`}
                value={address}
                onChangeText={handleAddressChange}
                onBlur={handleAddressComplete}
                onFocus={() => address.length > 2 && setShowSuggestions(true)}
                multiline={true}
                numberOfLines={2}
                textAlignVertical="top"
            />
            
            <Text style={styles.helperText}>
                📍 Enter full address manually (Maps offline)
            </Text>

            {/* Address suggestions */}
            {showSuggestions && combinedSuggestions.length > 0 && (
                <View style={styles.suggestionsContainer}>
                    <Text style={styles.suggestionsHeader}>Suggested addresses:</Text>
                    {combinedSuggestions.map((suggestion, index) => (
                        <Text
                            key={index}
                            style={styles.suggestion}
                            onPress={() => selectRecentAddress(suggestion)}
                        >
                            📍 {suggestion}
                        </Text>
                    ))}
                </View>
            )}

            {/* Address format hint */}
            {address.length > 0 && address.length < 15 && (
                <Text style={styles.formatHint}>
                    💡 Include street, city, state, ZIP for complete address
                </Text>
            )}
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        position: 'relative',
    },
    input: {
        height: 60,
        color: '#5d5d5d',
        fontSize: 16,
        borderWidth: 1,
        borderColor: '#ddd',
        padding: 12,
        backgroundColor: '#fff',
        borderRadius: 8,
        textAlignVertical: 'top',
    },
    helperText: {
        fontSize: 12,
        color: '#666',
        marginTop: 4,
        fontStyle: 'italic',
    },
    formatHint: {
        fontSize: 11,
        color: '#888',
        marginTop: 2,
    },
    suggestionsContainer: {
        backgroundColor: '#f9f9f9',
        borderWidth: 1,
        borderColor: '#ddd',
        borderTopWidth: 0,
        borderBottomLeftRadius: 8,
        borderBottomRightRadius: 8,
        maxHeight: 120,
    },
    suggestionsHeader: {
        fontSize: 12,
        color: '#666',
        padding: 8,
        backgroundColor: '#f0f0f0',
        fontWeight: 'bold',
    },
    suggestion: {
        padding: 10,
        borderBottomWidth: 1,
        borderBottomColor: '#eee',
        fontSize: 14,
        color: '#333',
    },
});

export default OfflineAddressInput;