import React, { useState } from 'react';
import { 
  View, 
  Text, 
  TextInput, 
  TouchableOpacity, 
  StyleSheet, 
  Alert, 
  ScrollView, 
  Dimensions,
  SafeAreaView 
} from 'react-native';

const { width } = Dimensions.get('window');

const SimpleApp = () => {
  const [teamName, setTeamName] = useState('');
  const [code, setCode] = useState('');
  const [isLoggedIn, setIsLoggedIn] = useState(false);

  const handleLogin = () => {
    if (!teamName || !code) {
      Alert.alert('Error', 'Please enter both team name and access code');
      return;
    }
    
    Alert.alert('Success', `Welcome ${teamName}!`, [
      { text: 'OK', onPress: () => setIsLoggedIn(true) }
    ]);
  };

  const handleLogout = () => {
    setIsLoggedIn(false);
    setTeamName('');
    setCode('');
  };

  if (isLoggedIn) {
    return (
      <SafeAreaView style={styles.container}>
        <View style={styles.loggedInContainer}>
          <Text style={styles.welcomeText}>Welcome, {teamName}!</Text>
          <Text style={styles.subtext}>BKMB Subsales Management</Text>
          <Text style={styles.infoText}>
            Login successful! This is a simplified version of the app for testing.
          </Text>
          <TouchableOpacity style={styles.button} onPress={handleLogout}>
            <Text style={styles.buttonText}>Logout</Text>
          </TouchableOpacity>
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container}>
      <ScrollView contentContainerStyle={styles.scrollContainer}>
        {/* Header */}
        <View style={styles.headerContainer}>
          <View style={styles.fallbackHeader}>
            <Text style={styles.fallbackText}>BKMB</Text>
            <Text style={styles.fallbackSubtext}>Southington Band</Text>
          </View>
        </View>
        
        {/* Login Form */}
        <View style={styles.formContainer}>
          <Text style={styles.title}>BKMB Subsales Login</Text>
          
          <TextInput
            style={styles.input}
            placeholder="Enter Team Name"
            value={teamName}
            onChangeText={setTeamName}
            autoCapitalize="words"
            placeholderTextColor="#999"
          />
          
          <TextInput
            style={styles.input}
            placeholder="Enter Access Code"
            value={code}
            onChangeText={setCode}
            secureTextEntry
            placeholderTextColor="#999"
          />
          
          <TouchableOpacity style={styles.button} onPress={handleLogin}>
            <Text style={styles.buttonText}>Login</Text>
          </TouchableOpacity>
          
          <Text style={styles.infoText}>
            This is a simplified version for testing the basic React Native functionality.
          </Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f5f5f5',
  },
  scrollContainer: {
    flexGrow: 1,
    justifyContent: 'center',
    minHeight: Dimensions.get('window').height - 100,
  },
  headerContainer: {
    alignItems: 'center',
    paddingTop: 40,
    paddingBottom: 20,
    paddingHorizontal: 16,
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
  formContainer: {
    flex: 1,
    justifyContent: 'center',
    padding: 20,
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
    color: '#333',
  },
  button: {
    backgroundColor: '#1a365d',
    paddingVertical: 12,
    paddingHorizontal: 32,
    borderRadius: 8,
    alignItems: 'center',
    marginBottom: 20,
  },
  buttonText: {
    color: '#fff',
    fontSize: 18,
    fontWeight: 'bold',
  },
  infoText: {
    fontSize: 14,
    color: '#666',
    textAlign: 'center',
    fontStyle: 'italic',
  },
  loggedInContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 20,
  },
  welcomeText: {
    fontSize: 28,
    fontWeight: 'bold',
    color: '#1a365d',
    marginBottom: 8,
    textAlign: 'center',
  },
  subtext: {
    fontSize: 18,
    color: '#666',
    marginBottom: 32,
    textAlign: 'center',
  },
});

export default SimpleApp;