import React, { useState, useEffect } from 'react';
import { View, Text, TextInput, Button, StyleSheet, Alert, Image, ScrollView, Dimensions } from 'react-native';
import { useDispatch, useSelector } from 'react-redux';
import { login } from '../store/authSlice';
import { setConfigSuccess, clearConfig } from '../store/configSlice';
import { login as authServiceLogin } from '../services/authService';
import { RootState } from '../store';

const { width } = Dimensions.get('window');

const AuthScreen = () => {
    const [code, setCode] = useState('');
    const [teamName, setTeamName] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [imageError, setImageError] = useState(false);
    
    const dispatch = useDispatch();
    const { isAuthenticated } = useSelector((state: RootState) => state.auth);
    const { isLoading: configLoading } = useSelector((state: RootState) => state.config);

    const handleLogin = async () => {
        if (!code || !teamName) {
            Alert.alert('Error', 'Please enter both code and team name');
            return;
        }

        setIsLoading(true);
        
        try {
            // Call auth service which handles both login and config fetch
            const result = await authServiceLogin(code, teamName);
            
            if (result.success) {
                // Update Redux auth state
                dispatch(login({ code, teamName }));
                
                // Update config if received
                if (result.config) {
                    dispatch(setConfigSuccess(result.config));
                }
                
                Alert.alert('Success', 'Login successful!');
            } else {
                Alert.alert('Error', result.message || 'Login failed');
            }
        } catch (error) {
            console.error('Login error:', error);
            Alert.alert('Error', 'Login failed. Please check your credentials and try again.');
            // Clear any existing config on login failure
            dispatch(clearConfig());
        } finally {
            setIsLoading(false);
        }
    };

    // Clear form when authenticated
    useEffect(() => {
        if (isAuthenticated) {
            setCode('');
            setTeamName('');
        }
    }, [isAuthenticated]);

    return (
        <ScrollView contentContainerStyle={styles.scrollContainer}>
            <View style={styles.container}>
                {/* Header Image */}
                <View style={styles.headerContainer}>
                    {!imageError ? (
                        <Image
                            source={{ uri: 'https://www.southingtonbkmb.com/wp-content/uploads/2024/05/cropped-cropped-cropped-Header_BKMB-Band-1.png' }}
                            style={styles.headerImage}
                            resizeMode="contain"
                            onError={() => setImageError(true)}
                        />
                    ) : (
                        <View style={styles.fallbackHeader}>
                            <Text style={styles.fallbackText}>BKMB</Text>
                            <Text style={styles.fallbackSubtext}>Southington Band</Text>
                        </View>
                    )}
                </View>
                
                {/* Login Form */}
                <View style={styles.formContainer}>
                    <Text style={styles.title}>BKMB Subsales Login</Text>
                    
                    <TextInput
                        style={styles.input}
                        placeholder="Enter Team Name"
                        value={teamName}
                        onChangeText={setTeamName}
                        editable={!isLoading}
                        autoCapitalize="words"
                    />
                    
                    <TextInput
                        style={styles.input}
                        placeholder="Enter Access Code"
                        value={code}
                        onChangeText={setCode}
                        editable={!isLoading}
                        secureTextEntry
                    />
                    
                    <Button 
                        title={isLoading ? "Logging in..." : "Login"} 
                        onPress={handleLogin}
                        disabled={isLoading || configLoading}
                    />
                    
                    {configLoading && (
                        <Text style={styles.statusText}>Loading configuration...</Text>
                    )}
                </View>
            </View>
        </ScrollView>
    );
};

const styles = StyleSheet.create({
    scrollContainer: {
        flexGrow: 1,
        backgroundColor: '#f5f5f5',
    },
    container: {
        flex: 1,
        justifyContent: 'center',
        minHeight: Dimensions.get('window').height,
    },
    headerContainer: {
        alignItems: 'center',
        paddingTop: 40,
        paddingBottom: 20,
        paddingHorizontal: 16,
    },
    headerImage: {
        width: width * 0.9,
        height: 100,
        maxWidth: 400,
    },
    formContainer: {
        flex: 1,
        justifyContent: 'center',
        padding: 16,
        paddingTop: 20,
    },
    title: {
        fontSize: 24,
        fontWeight: 'bold',
        marginBottom: 32,
        textAlign: 'center',
        color: '#333',
    },
    input: {
        height: 48,
        borderColor: '#ddd',
        borderWidth: 1,
        marginBottom: 16,
        paddingHorizontal: 12,
        borderRadius: 8,
        backgroundColor: '#fff',
        fontSize: 16,
    },
    statusText: {
        marginTop: 16,
        textAlign: 'center',
        color: '#666',
        fontSize: 14,
    },
    fallbackHeader: {
        alignItems: 'center',
        justifyContent: 'center',
        height: 100,
        width: width * 0.9,
        backgroundColor: '#1a365d',
        borderRadius: 8,
    },
    fallbackText: {
        fontSize: 28,
        fontWeight: 'bold',
        color: '#ffffff',
        letterSpacing: 2,
    },
    fallbackSubtext: {
        fontSize: 14,
        color: '#e2e8f0',
        marginTop: 4,
    },
});

export default AuthScreen;